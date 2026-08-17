<?php

declare(strict_types=1);

use App\Application\Conversations\Services\ConversationService;
use App\Application\Flows\Services\FlowExecutionService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\ConversationAssignment;
use App\Domain\Conversations\Models\ConversationParticipant;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Events\ConversationUpdated;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    TenantContext::clear();
});

/**
 * @return array{tenant: Tenant, owner: User, agents: list<User>, conversation: Conversation}
 */
function pg_handoff_setup(int $agentCount = 3, bool $handoff = true): array
{
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $agents = [];

    for ($index = 0; $index < $agentCount; $index++) {
        $agent = User::factory()->create();
        make_tenant_member($agent, $tenant, 'agent');
        $agents[] = $agent;
    }

    $conversation = make_conversation($tenant, make_contact($tenant));

    if ($handoff) {
        $conversation->forceFill([
            'bot_paused' => true,
            'handoff_requested_at' => now(),
        ])->save();
    }

    return compact('tenant', 'owner', 'agents', 'conversation');
}

function pg_call_service(
    string $operation,
    User $actor,
    Tenant $tenant,
    Conversation $conversation,
    ?User $target = null,
): Conversation {
    return TenantContext::withId($tenant->id, function () use ($operation, $actor, $tenant, $conversation, $target): Conversation {
        $service = app(ConversationService::class);

        return match ($operation) {
            'assign' => $service->assign($actor, $tenant, $conversation->id, $target?->id ?? 0),
            'transfer' => $service->transfer($actor, $tenant, $conversation->id, $target?->id ?? 0),
            'claim' => $service->claim($actor, $tenant, $conversation->id),
            default => throw new InvalidArgumentException("Operación desconocida: {$operation}"),
        };
    });
}

function pg_worker(
    string $label,
    string $operation,
    Tenant $tenant,
    User $actor,
    Conversation $conversation,
    ?User $target = null,
): Process {
    $environment = [
        'APP_ENV' => 'testing',
        'HANDOFF_U2_PG_TEST' => '1',
        'CACHE_STORE' => 'redis',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'whatsapp_saas_handoff_u2_test',
        'DB_USERNAME' => 'saas',
        'DB_PASSWORD' => 'saas_secret',
        'REDIS_HOST' => 'redis',
        'REDIS_PORT' => '6379',
        'REDIS_DB' => '14',
        'REDIS_CACHE_DB' => '14',
        'QUEUE_CONNECTION' => 'sync',
        'BROADCAST_CONNECTION' => 'null',
        'LOG_CHANNEL' => 'null',
    ];

    $process = new Process([
        PHP_BINARY,
        base_path('tests/Support/PostgresHandoffWorker.php'),
        $label,
        $operation,
        $tenant->id,
        (string) $actor->id,
        $conversation->id,
        (string) ($target?->id ?? 0),
    ], base_path(), $environment);
    $process->setTimeout(45);

    return $process;
}

/**
 * @return array<string, mixed>
 */
function pg_worker_result(Process $process): array
{
    $process->wait();
    $lines = array_values(array_filter(explode(PHP_EOL, trim($process->getOutput()))));
    $result = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($result)) {
        throw new RuntimeException('El worker PostgreSQL no devolvió JSON válido.');
    }

    return $result;
}

/**
 * @param  array<string, Process>  $workers
 * @return list<array<string, mixed>>
 */
function pg_release_gate_and_collect(Lock $lock, array $workers): array
{
    foreach ($workers as $worker) {
        $worker->start();
    }

    foreach ($workers as $label => $worker) {
        $ready = pg_wait_until(
            $label,
            static fn (object $activity): bool => $activity->state === 'idle',
        );

        usleep(200_000);

        if (! $ready) {
            $lock->release();

            foreach ($workers as $runningWorker) {
                if ($runningWorker->isRunning()) {
                    $runningWorker->stop();
                }
            }

            throw new RuntimeException("El worker {$label} no alcanzó el lock Redis.");
        }
    }

    $lock->release();

    return array_values(array_map(pg_worker_result(...), $workers));
}

function pg_assert_projection_consistency(Conversation $conversation): void
{
    $conversation->refresh();
    $open = ConversationAssignment::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->whereNull('unassigned_at')
        ->get();
    $active = ConversationParticipant::withoutTenantScope()
        ->where('conversation_id', $conversation->id)
        ->whereNull('left_at')
        ->where('user_id', $conversation->agent_id)
        ->get();

    expect($open)->toHaveCount(1)
        ->and((int) $open->firstOrFail()->agent_id)->toBe($conversation->agent_id)
        ->and($active)->toHaveCount(1);
}

function pg_observer(): PDO
{
    return new PDO(
        'pgsql:host=postgres;port=5432;dbname=whatsapp_saas_handoff_u2_test',
        'saas',
        'saas_secret',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function pg_wait_until(string $applicationName, callable $predicate, float $seconds = 30): bool
{
    $observer = pg_observer();
    $statement = $observer->prepare(
        'SELECT state, wait_event_type, query FROM pg_stat_activity WHERE application_name = :name',
    );
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $statement->execute(['name' => $applicationName]);
        $activity = $statement->fetch(PDO::FETCH_OBJ);

        if ($activity !== false && $predicate($activity)) {
            return true;
        }

        usleep(50_000);
    }

    return false;
}

test('HCON-ROW-01: claim espera el SELECT FOR UPDATE de conversation', function (): void {
    ['tenant' => $tenant, 'agents' => [$agent], 'conversation' => $conversation] = pg_handoff_setup(agentCount: 1);
    $label = 'u2-row-lock-'.bin2hex(random_bytes(4));

    DB::beginTransaction();
    DB::table('conversations')->where('id', $conversation->id)->lockForUpdate()->first();
    $worker = pg_worker($label, 'claim', $tenant, $agent, $conversation);
    $worker->start();

    try {
        $waiting = pg_wait_until(
            $label,
            static fn (object $activity): bool => $activity->wait_event_type === 'Lock',
        );

        expect($waiting)->toBeTrue();
        DB::commit();

        expect(pg_worker_result($worker)['status'])->toBe('ok');
        pg_assert_projection_consistency($conversation);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});

test('HCON-01: dos assigns concurrentes dejan un ganador consistente', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agents' => [$agentA, $agentB], 'conversation' => $conversation] = pg_handoff_setup(agentCount: 2, handoff: false);
    $gate = app(FlowExecutionService::class)->conversationLock($tenant, $conversation->id);
    expect($gate->get())->toBeTrue();

    $results = pg_release_gate_and_collect($gate, [
        'u2-assign-a' => pg_worker('u2-assign-a', 'assign', $tenant, $owner, $conversation, $agentA),
        'u2-assign-b' => pg_worker('u2-assign-b', 'assign', $tenant, $owner, $conversation, $agentB),
    ]);

    $counts = array_count_values(array_column($results, 'status'));
    expect($counts['ok'] ?? 0)->toBe(1)
        ->and($counts['conflict'] ?? 0)->toBe(1);
    pg_assert_projection_consistency($conversation);
});

test('HCON-02: dos transfers concurrentes se serializan sin perder historial', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agents' => [$agentA, $agentB, $agentC], 'conversation' => $conversation] = pg_handoff_setup();
    pg_call_service('assign', $owner, $tenant, $conversation, $agentA);
    $gate = app(FlowExecutionService::class)->conversationLock($tenant, $conversation->id);
    expect($gate->get())->toBeTrue();

    $results = pg_release_gate_and_collect($gate, [
        'u2-transfer-b' => pg_worker('u2-transfer-b', 'transfer', $tenant, $owner, $conversation, $agentB),
        'u2-transfer-c' => pg_worker('u2-transfer-c', 'transfer', $tenant, $owner, $conversation, $agentC),
    ]);

    expect(array_column($results, 'status'))->each->toBe('ok')
        ->and(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(3);
    pg_assert_projection_consistency($conversation);
});

test('HCON-03: claim vs assign produce un único ganador', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agents' => [$agentA, $agentB], 'conversation' => $conversation] = pg_handoff_setup(agentCount: 2);
    $gate = app(FlowExecutionService::class)->conversationLock($tenant, $conversation->id);
    expect($gate->get())->toBeTrue();

    $results = pg_release_gate_and_collect($gate, [
        'u2-claim-race' => pg_worker('u2-claim-race', 'claim', $tenant, $agentA, $conversation),
        'u2-assign-race' => pg_worker('u2-assign-race', 'assign', $tenant, $owner, $conversation, $agentB),
    ]);

    $counts = array_count_values(array_column($results, 'status'));
    expect($counts['ok'] ?? 0)->toBe(1)
        ->and($counts['conflict'] ?? 0)->toBe(1);
    pg_assert_projection_consistency($conversation);
});

test('HCON-04: transfer vs claim mantienen la asignación consistente', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agents' => [$agentA, $agentB, $agentC], 'conversation' => $conversation] = pg_handoff_setup();
    pg_call_service('assign', $owner, $tenant, $conversation, $agentA);
    $gate = app(FlowExecutionService::class)->conversationLock($tenant, $conversation->id);
    expect($gate->get())->toBeTrue();

    $results = pg_release_gate_and_collect($gate, [
        'u2-transfer-race' => pg_worker('u2-transfer-race', 'transfer', $tenant, $owner, $conversation, $agentB),
        'u2-claim-assigned' => pg_worker('u2-claim-assigned', 'claim', $tenant, $agentC, $conversation),
    ]);

    $counts = array_count_values(array_column($results, 'status'));
    expect($counts['ok'] ?? 0)->toBe(1)
        ->and($counts['conflict'] ?? 0)->toBe(1);
    pg_assert_projection_consistency($conversation);
});

test('HCON-05: fallo tardío de audit revierte transfer completa y no emite realtime', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agents' => [$agentA, $agentB], 'conversation' => $conversation] = pg_handoff_setup(agentCount: 2);
    pg_call_service('assign', $owner, $tenant, $conversation, $agentA);
    Event::fake([ConversationUpdated::class]);

    DB::unprepared(<<<'SQL'
CREATE FUNCTION u2_fail_transfer_audit() RETURNS trigger AS $$
BEGIN
    IF NEW.action = 'conversation.transferred' THEN
        RAISE EXCEPTION 'forced transfer audit failure' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER u2_fail_transfer_audit_trigger
BEFORE INSERT ON audit_logs
FOR EACH ROW EXECUTE FUNCTION u2_fail_transfer_audit();
SQL);

    try {
        expect(fn (): Conversation => pg_call_service('transfer', $owner, $tenant, $conversation, $agentB))
            ->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS u2_fail_transfer_audit_trigger ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS u2_fail_transfer_audit()');
    }

    $conversation->refresh();
    expect($conversation->agent_id)->toBe($agentA->id)
        ->and(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->whereNull('unassigned_at')->value('agent_id'))->toBe($agentA->id)
        ->and(ConversationParticipant::withoutTenantScope()->where('conversation_id', $conversation->id)->where('user_id', $agentA->id)->whereNull('left_at')->exists())->toBeTrue()
        ->and(ConversationParticipant::withoutTenantScope()->where('conversation_id', $conversation->id)->where('user_id', $agentB->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'conversation.transferred')->exists())->toBeFalse();
    Event::assertNotDispatched(ConversationUpdated::class);
});

test('HCON-06: UNIQUE parcial bloquea insert concurrente con SQLSTATE 23505', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agents' => [$agentA, $agentB], 'conversation' => $conversation] = pg_handoff_setup(agentCount: 2, handoff: false);

    DB::beginTransaction();
    DB::table('conversation_assignments')->insert([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'agent_id' => $agentA->id,
        'assigned_by' => $owner->id,
        'assigned_at' => now(),
        'reason' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $worker = pg_worker('u2-unique-backstop', 'raw-assignment', $tenant, $owner, $conversation, $agentB);
    $worker->start();

    try {
        $waiting = pg_wait_until(
            'u2-unique-backstop',
            static fn (object $activity): bool => $activity->wait_event_type === 'Lock',
        );

        expect($waiting)->toBeTrue();
        DB::commit();

        $result = pg_worker_result($worker);
        expect($result['status'])->toBe('db_conflict')
            ->and($result['sqlstate'])->toBe('23505')
            ->and(ConversationAssignment::withoutTenantScope()->where('conversation_id', $conversation->id)->whereNull('unassigned_at')->count())->toBe(1);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});

test('HC-07-PG: membership desactivada mientras espera lock impide claim', function (): void {
    ['tenant' => $tenant, 'agents' => [$agent], 'conversation' => $conversation] = pg_handoff_setup(agentCount: 1);
    $label = 'u2-membership-recheck';
    $gate = app(FlowExecutionService::class)->conversationLock($tenant, $conversation->id);
    expect($gate->get())->toBeTrue();
    $worker = pg_worker($label, 'claim', $tenant, $agent, $conversation);
    $worker->start();

    try {
        $authorizedBeforeWait = pg_wait_until(
            $label,
            static fn (object $activity): bool => $activity->state === 'idle',
        );

        usleep(200_000);

        expect($authorizedBeforeWait)->toBeTrue();
        DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $agent->id)
            ->update(['status' => 'disabled']);
        $gate->release();

        expect(pg_worker_result($worker)['status'])->toBe('membership_error')
            ->and($conversation->fresh()?->agent_id)->toBeNull();
    } finally {
        $gate->release();

        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});

test('HCON-MEMBER-02: target desactivado mientras assign espera lock es rechazado', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'agents' => [$agent], 'conversation' => $conversation] = pg_handoff_setup(agentCount: 1, handoff: false);
    $label = 'u2-target-recheck';
    $gate = app(FlowExecutionService::class)->conversationLock($tenant, $conversation->id);
    expect($gate->get())->toBeTrue();
    $worker = pg_worker($label, 'assign', $tenant, $owner, $conversation, $agent);
    $worker->start();

    try {
        $authorizedBeforeWait = pg_wait_until(
            $label,
            static fn (object $activity): bool => $activity->state === 'idle',
        );
        usleep(200_000);

        expect($authorizedBeforeWait)->toBeTrue();
        DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $agent->id)
            ->update(['status' => 'disabled']);
        $gate->release();

        expect(pg_worker_result($worker)['status'])->toBe('target_membership_error')
            ->and($conversation->fresh()?->agent_id)->toBeNull();
    } finally {
        $gate->release();

        if ($worker->isRunning()) {
            $worker->stop();
        }
    }
});
