<?php

declare(strict_types=1);

use App\Application\Billing\Guards\CapacityGuard;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Contacts\Models\Contact;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\InvitationStatus;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\TenantInvitation;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    TenantContext::clear();
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * @param  array<string, int|null>  $limits
 */
function cap_pg_entitle(Tenant $tenant, array $limits): void
{
    $plan = Plan::factory()->create([
        'limits' => array_merge([
            'messages' => null,
            'ai_tokens' => null,
            'contacts' => null,
            'flow_executions' => null,
            'users' => null,
            'knowledge_documents' => null,
        ], $limits),
    ]);

    TenantContext::withId($tenant->id, fn (): Subscription => Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]));
}

function cap_pg_member(Tenant $tenant, UserRole $role = UserRole::Agent): User
{
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, $role->value);

    return $user;
}

function cap_pg_kb(Tenant $tenant): KnowledgeBase
{
    return TenantContext::withId($tenant->id, fn (): KnowledgeBase => KnowledgeBase::query()->create([
        'name' => 'PG Capacity KB '.Str::random(8),
    ]));
}

function cap_pg_document(Tenant $tenant, KnowledgeBase $knowledgeBase): KnowledgeDocument
{
    return TenantContext::withId($tenant->id, fn (): KnowledgeDocument => KnowledgeDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $knowledgeBase->id,
        'file_hash' => hash('sha256', (string) Str::uuid()),
    ]));
}

/**
 * @return array{TenantInvitation, string}
 */
function cap_pg_invitation(Tenant $tenant, User $owner, User $invited): array
{
    $token = Str::random(64);
    $invitation = TenantInvitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => $invited->email,
        'role' => UserRole::Agent,
        'token_hash' => hash('sha256', $token),
        'invited_by' => $owner->id,
        'status' => InvitationStatus::Pending,
        'expires_at' => now()->addDays(7),
    ]);

    return [$invitation, $token];
}

function cap_pg_worker(
    string $label,
    string $runId,
    string $operation,
    Tenant $tenant,
    User|string|int|null $actor,
    string $resourceId,
    string $value = '',
): Process {
    $actorId = $actor instanceof User ? (string) $actor->id : (string) ($actor ?? 0);
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
        'QUEUE_CONNECTION' => 'database',
        'KNOWLEDGE_STORAGE_DISK' => 'local',
        'BROADCAST_CONNECTION' => 'null',
        'LOG_CHANNEL' => 'null',
    ];

    $process = new Process([
        PHP_BINARY,
        base_path('tests/Support/PostgresCapacityWorker.php'),
        $label,
        $runId,
        $operation,
        $tenant->id,
        $actorId,
        $resourceId,
        $value,
    ], base_path(), $environment);
    $process->setTimeout(45);

    return $process;
}

/**
 * @return array<string, mixed>
 */
function cap_pg_result(Process $process): array
{
    $process->wait();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            "Capacity worker failed with exit {$process->getExitCode()}: {$process->getErrorOutput()}",
        );
    }

    $lines = array_values(array_filter(explode(PHP_EOL, trim($process->getOutput()))));
    $result = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($result)) {
        throw new RuntimeException('Capacity worker did not return JSON.');
    }

    return $result;
}

function cap_pg_wait_for(string $key, int $expected, float $seconds = 30): void
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if ((int) Redis::get($key) >= $expected) {
            return;
        }

        usleep(20_000);
    }

    throw new RuntimeException("Timed out waiting for Redis key [{$key}].");
}

/**
 * @param  list<Process>  $workers
 * @return list<array<string, mixed>>
 */
function cap_pg_race(string $runId, array $workers): array
{
    Redis::del("capacity-pg:{$runId}:ready", "capacity-pg:{$runId}:release");

    try {
        foreach ($workers as $worker) {
            $worker->start();
        }

        cap_pg_wait_for("capacity-pg:{$runId}:ready", count($workers));
        Redis::setex("capacity-pg:{$runId}:release", 60, '1');

        return array_map(cap_pg_result(...), $workers);
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        Redis::del("capacity-pg:{$runId}:ready", "capacity-pg:{$runId}:release");
    }
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function cap_pg_expect_one_winner(array $results, string $category): void
{
    $statusCounts = array_count_values(array_column($results, 'status'));

    expect($statusCounts['ok'] ?? 0)->toBe(1)
        ->and($statusCounts['quota'] ?? 0)->toBe(1)
        ->and(count($statusCounts))->toBe(2);

    $quota = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'quota'))[0];
    expect($quota['category'])->toBe($category);

    $winner = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'ok'))[0];
    expect($winner['guard_class'])->toBe(CapacityGuard::class);
}

test('CAP-U4-PG-CONTACT-01: two contacts compete for the final slot', function (): void {
    $tenant = Tenant::factory()->create();
    cap_pg_entitle($tenant, ['contacts' => 10]);

    for ($index = 0; $index < 9; $index++) {
        make_contact($tenant, ['phone' => '+1555100'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)]);
    }

    $runId = (string) Str::uuid();
    $results = cap_pg_race($runId, [
        cap_pg_worker('cap-contact-a-'.$runId, $runId, 'contact-find', $tenant, null, '15552000001'),
        cap_pg_worker('cap-contact-b-'.$runId, $runId, 'contact-find', $tenant, null, '15552000002'),
    ]);

    cap_pg_expect_one_winner($results, 'contacts');
    expect(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(10);
});

test('CAP-U4-PG-CONTACT-02: concurrent same normalized phone creates one contact', function (): void {
    $tenant = Tenant::factory()->create();
    cap_pg_entitle($tenant, ['contacts' => 10]);

    for ($index = 0; $index < 9; $index++) {
        make_contact($tenant, ['phone' => '+1555300'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)]);
    }

    $runId = (string) Str::uuid();
    $results = cap_pg_race($runId, [
        cap_pg_worker('cap-same-contact-a-'.$runId, $runId, 'contact-find', $tenant, null, '+1 (555) 400-0001'),
        cap_pg_worker('cap-same-contact-b-'.$runId, $runId, 'contact-find', $tenant, null, '15554000001'),
    ]);

    expect(array_column($results, 'status'))->toBe(['ok', 'ok'])
        ->and($results[0]['resource_id'])->toBe($results[1]['resource_id'])
        ->and(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(10);
});

test('CAP-U4-PG-USER-01: two invitation acceptances compete for the final seat', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_pg_member($tenant, UserRole::Owner);

    for ($index = 0; $index < 3; $index++) {
        cap_pg_member($tenant, UserRole::Agent);
    }

    cap_pg_entitle($tenant, ['users' => 5]);
    $invitedA = User::factory()->create(['email' => 'pg-seat-a@example.test']);
    $invitedB = User::factory()->create(['email' => 'pg-seat-b@example.test']);
    [, $tokenA] = cap_pg_invitation($tenant, $owner, $invitedA);
    [, $tokenB] = cap_pg_invitation($tenant, $owner, $invitedB);
    $runId = (string) Str::uuid();
    $results = cap_pg_race($runId, [
        cap_pg_worker('cap-user-a-'.$runId, $runId, 'user-accept', $tenant, $invitedA, $tokenA),
        cap_pg_worker('cap-user-b-'.$runId, $runId, 'user-accept', $tenant, $invitedB, $tokenB),
    ]);

    cap_pg_expect_one_winner($results, 'users');
    expect(TenantUser::query()->where('tenant_id', $tenant->id)->where('status', TenantMembershipStatus::Active)->count())->toBe(5)
        ->and(TenantInvitation::query()->where('tenant_id', $tenant->id)->where('status', InvitationStatus::Accepted)->count())->toBe(1)
        ->and(TenantInvitation::query()->where('tenant_id', $tenant->id)->where('status', InvitationStatus::Pending)->count())->toBe(1);
});

test('CAP-U4-PG-KB-01: two document uploads compete for the final slot', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_pg_member($tenant, UserRole::Owner);
    cap_pg_entitle($tenant, ['knowledge_documents' => 10]);
    $knowledgeBase = cap_pg_kb($tenant);

    for ($index = 0; $index < 9; $index++) {
        cap_pg_document($tenant, $knowledgeBase);
    }

    Storage::disk('local')->deleteDirectory("knowledge/tenant/{$tenant->id}");
    $runId = (string) Str::uuid();
    $results = cap_pg_race($runId, [
        cap_pg_worker('cap-kb-a-'.$runId, $runId, 'document-upload', $tenant, $owner, $knowledgeBase->id, 'a-'.$runId),
        cap_pg_worker('cap-kb-b-'.$runId, $runId, 'document-upload', $tenant, $owner, $knowledgeBase->id, 'b-'.$runId),
    ]);

    cap_pg_expect_one_winner($results, 'knowledge_documents');
    expect(KnowledgeDocument::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(10)
        ->and(Storage::disk('local')->allFiles("knowledge/tenant/{$tenant->id}"))->toHaveCount(1);

    Storage::disk('local')->deleteDirectory("knowledge/tenant/{$tenant->id}");
});

test('CAP-U4-PG-LOCK-01: same category remains independent across tenants', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    cap_pg_entitle($tenantA, ['contacts' => 1]);
    cap_pg_entitle($tenantB, ['contacts' => 1]);
    $runId = (string) Str::uuid();
    $holder = cap_pg_worker('cap-hold-tenant-'.$runId, $runId, 'hold-lock', $tenantA, null, '-', 'contacts');
    $probe = cap_pg_worker('cap-probe-tenant-'.$runId, $runId, 'probe-lock', $tenantB, null, '-', 'contacts');
    Redis::del("capacity-pg:{$runId}:hold-ready", "capacity-pg:{$runId}:hold-release", "capacity-pg:{$runId}:ready", "capacity-pg:{$runId}:release");

    try {
        $holder->start();
        cap_pg_wait_for("capacity-pg:{$runId}:hold-ready", 1);
        $probe->start();
        cap_pg_wait_for("capacity-pg:{$runId}:ready", 1);
        Redis::setex("capacity-pg:{$runId}:release", 60, '1');
        $probeResult = cap_pg_result($probe);

        expect($probeResult['status'])->toBe('ok')->and($holder->isRunning())->toBeTrue();
    } finally {
        Redis::setex("capacity-pg:{$runId}:hold-release", 60, '1');

        if ($holder->isRunning()) {
            cap_pg_result($holder);
        }

        foreach ([$holder, $probe] as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
});

test('CAP-U4-PG-LOCK-02: categories remain independent within one tenant', function (): void {
    $tenant = Tenant::factory()->create();
    cap_pg_entitle($tenant, ['contacts' => 1, 'users' => 1, 'knowledge_documents' => 1]);
    $runId = (string) Str::uuid();
    $holder = cap_pg_worker('cap-hold-category-'.$runId, $runId, 'hold-lock', $tenant, null, '-', 'contacts');
    $userProbe = cap_pg_worker('cap-probe-users-'.$runId, $runId, 'probe-lock', $tenant, null, '-', 'users');
    $documentProbe = cap_pg_worker('cap-probe-documents-'.$runId, $runId, 'probe-lock', $tenant, null, '-', 'knowledge_documents');
    Redis::del("capacity-pg:{$runId}:hold-ready", "capacity-pg:{$runId}:hold-release", "capacity-pg:{$runId}:ready", "capacity-pg:{$runId}:release");

    try {
        $holder->start();
        cap_pg_wait_for("capacity-pg:{$runId}:hold-ready", 1);
        $userProbe->start();
        $documentProbe->start();
        cap_pg_wait_for("capacity-pg:{$runId}:ready", 2);
        Redis::setex("capacity-pg:{$runId}:release", 60, '1');

        expect(cap_pg_result($userProbe)['status'])->toBe('ok')
            ->and(cap_pg_result($documentProbe)['status'])->toBe('ok')
            ->and($holder->isRunning())->toBeTrue();
    } finally {
        Redis::setex("capacity-pg:{$runId}:hold-release", 60, '1');

        if ($holder->isRunning()) {
            cap_pg_result($holder);
        }

        foreach ([$holder, $userProbe, $documentProbe] as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
});
