<?php

declare(strict_types=1);

use App\Application\Conversations\Services\ConversationService;
use App\Application\Flows\Services\FlowExecutionService;
use App\Domain\Conversations\Exceptions\ConversationAgentNotInTenantException;
use App\Domain\Conversations\Exceptions\ConversationAssignmentConflictException;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $applicationName, $operation, $tenantId, $actorId, $conversationId] = $argv;
$targetId = $argv[6] ?? null;
$agentId = $targetId === null ? null : (int) $targetId;

try {
    $database = DB::selectOne('SELECT current_database() AS name');

    if (env('HANDOFF_U2_PG_TEST') !== '1'
        || DB::getDriverName() !== 'pgsql'
        || config('database.connections.pgsql.host') !== 'postgres'
        || $database?->name !== 'whatsapp_saas_handoff_u2_test'
        || config('cache.default') !== 'redis'
        || config('database.redis.default.host') !== 'redis'
        || (string) config('database.redis.default.database') !== '14') {
        throw new RuntimeException('Worker U2 rechazó una configuración de test insegura.');
    }

    DB::select("SELECT set_config('application_name', ?, false)", [$applicationName]);

    if ($operation === 'raw-assignment') {
        DB::table('conversation_assignments')->insert([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'agent_id' => $agentId,
            'assigned_by' => $actorId,
            'assigned_at' => now(),
            'reason' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $tenant = Tenant::query()->findOrFail($tenantId);
    $actor = User::query()->findOrFail((int) $actorId);

    TenantContext::setId($tenantId);

    if ($operation === 'send-message') {
        $probe = app(FlowExecutionService::class)->conversationLock($tenant, $conversationId);
        $contended = ! $probe->get();

        if (! $contended) {
            $probe->release();
            throw new RuntimeException('El worker outbound adquirió el lock de prueba antes del handoff.');
        }

        Cache::put('handoff-pg-ready:'.$applicationName, $contended, 30);
        (new SendWhatsAppMessage($tenantId, $conversationId, (string) $targetId))->handle();
        $message = Message::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereKey((string) $targetId)
            ->firstOrFail();

        echo json_encode([
            'status' => 'ok',
            'message_status' => $message->status->value,
            'error_code' => $message->metadata['error_code'] ?? null,
        ], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }

    $service = app(ConversationService::class);

    $conversation = match ($operation) {
        'assign' => $service->assign($actor, $tenant, $conversationId, (int) $agentId),
        'transfer' => $service->transfer($actor, $tenant, $conversationId, (int) $agentId),
        'claim' => $service->claim($actor, $tenant, $conversationId),
        default => throw new InvalidArgumentException("Operación desconocida: {$operation}"),
    };

    echo json_encode([
        'status' => 'ok',
        'agent_id' => $conversation->agent_id,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ConversationAssignmentConflictException $exception) {
    echo json_encode([
        'status' => 'conflict',
        'code' => $exception->errorCode,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (TenantMembershipException) {
    echo json_encode([
        'status' => 'membership_error',
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ConversationAgentNotInTenantException) {
    echo json_encode([
        'status' => 'target_membership_error',
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (QueryException $exception) {
    echo json_encode([
        'status' => 'db_conflict',
        'sqlstate' => $exception->errorInfo[0] ?? null,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    TenantContext::clear();
}
