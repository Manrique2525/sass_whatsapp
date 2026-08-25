<?php

declare(strict_types=1);

use App\Application\Contacts\Services\ContactService;
use App\Application\KnowledgeBase\Services\DocumentService;
use App\Application\Users\Services\InvitationService;
use App\Domain\Billing\Contracts\CapacityCheckInterface;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\SubscriptionNotActiveException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\KnowledgeBase\Exceptions\DocumentDuplicateException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $applicationName, $runId, $operation, $tenantId, $actorId, $resourceId, $value] = $argv;
$temporaryFile = null;

/**
 * @return array<string, mixed>
 */
function capacity_worker_execute(
    string $operation,
    Tenant $tenant,
    string $actorId,
    string $resourceId,
    string $value,
): array {
    return match ($operation) {
        'contact-find' => (function () use ($tenant, $resourceId): array {
            $contact = app(ContactService::class)
                ->findOrCreateForPhone($tenant, $resourceId);

            return ['status' => 'ok', 'resource_id' => $contact->id];
        })(),
        'user-accept' => (function () use ($actorId, $resourceId): array {
            $user = User::query()->findOrFail((int) $actorId);
            $invitation = app(InvitationService::class)
                ->accept($user, $resourceId);

            return ['status' => 'ok', 'resource_id' => $invitation->id];
        })(),
        'document-upload' => (function () use ($tenant, $actorId, $resourceId, $value): array {
            $user = User::query()->findOrFail((int) $actorId);
            $temporaryFile = tempnam(sys_get_temp_dir(), 'cap_pg_');

            if ($temporaryFile === false) {
                throw new RuntimeException('Could not create temporary upload file.');
            }

            file_put_contents(
                $temporaryFile,
                "PostgreSQL capacity document {$value} with valid UTF-8 test content.",
            );

            try {
                $document = app(DocumentService::class)->upload(
                    $user,
                    $tenant,
                    $resourceId,
                    new UploadedFile($temporaryFile, "capacity-{$value}.txt", 'text/plain', null, true),
                );
            } finally {
                if (is_file($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }

            return ['status' => 'ok', 'resource_id' => $document->id];
        })(),
        'probe-lock' => app(CapacityGuardInterface::class)->withinLock(
            $tenant,
            UsageCategory::from($value),
            static fn (CapacityCheckInterface $check): array => ['status' => 'ok'],
        ),
        default => throw new InvalidArgumentException("Unknown capacity operation [{$operation}]."),
    };
}

try {
    $database = DB::selectOne('SELECT current_database() AS name');

    if (env('HANDOFF_U2_PG_TEST') !== '1'
        || DB::getDriverName() !== 'pgsql'
        || config('database.connections.pgsql.host') !== 'postgres'
        || $database?->name !== 'whatsapp_saas_handoff_u2_test'
        || config('cache.default') !== 'redis'
        || config('database.redis.default.host') !== 'redis'
        || (string) config('database.redis.default.database') !== '14') {
        throw new RuntimeException('Capacity worker rejected unsafe test configuration.');
    }

    DB::select("SELECT set_config('application_name', ?, false)", [$applicationName]);
    $tenant = Tenant::query()->findOrFail($tenantId);
    TenantContext::setId($tenantId);

    if ($operation === 'hold-lock') {
        $result = app(CapacityGuardInterface::class)->withinLock(
            $tenant,
            UsageCategory::from($value),
            function (CapacityCheckInterface $check) use ($runId): array {
                Redis::setex("capacity-pg:{$runId}:hold-ready", 60, '1');
                $deadline = microtime(true) + 30;

                while (Redis::get("capacity-pg:{$runId}:hold-release") !== '1') {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Timed out while holding capacity lock.');
                    }

                    usleep(20_000);
                }

                return ['status' => 'ok'];
            },
        );
    } else {
        Redis::incr("capacity-pg:{$runId}:ready");
        Redis::expire("capacity-pg:{$runId}:ready", 60);
        $deadline = microtime(true) + 30;

        while (Redis::get("capacity-pg:{$runId}:release") !== '1') {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for capacity race barrier.');
            }

            usleep(20_000);
        }

        $result = capacity_worker_execute($operation, $tenant, $actorId, $resourceId, $value);
    }

    $result['guard_class'] = app(CapacityGuardInterface::class)::class;
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (TenantQuotaExceededException $exception) {
    echo json_encode([
        'status' => 'quota',
        'category' => $exception->category,
        'limit' => $exception->limit,
        'used' => $exception->used,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (SubscriptionNotFoundException|SubscriptionNotActiveException $exception) {
    echo json_encode([
        'status' => 'subscription',
        'class' => $exception::class,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (DocumentDuplicateException) {
    echo json_encode(['status' => 'duplicate'], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'error',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if (is_string($temporaryFile) && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }

    TenantContext::clear();
    DB::disconnect();
}
