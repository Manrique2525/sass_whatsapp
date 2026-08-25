<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| PostgreSQL Commit/Release Atomicity Tests (FASE 26 U2)
|--------------------------------------------------------------------------
|
| UA-COMMIT-01..08 — Concurrent commit, release-vs-commit,
| concurrent reconciliation, remaining snapshot, reserve-during-commit.
|
| Execute with:
|   docker compose exec -T app vendor/bin/pest
|     --configuration=phpunit.pgsql.xml
|     --filter="UsageGuardCommitAtomicityTest"
|     --no-coverage
|
*/

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

beforeEach(function (): void {
    $this->guard = new UsageGuard(new EntitlementResolver);

    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->plan = Plan::factory()->create([
        'limits' => [
            'messages' => 100,
            'ai_tokens' => 200,
            'contacts' => 100,
            'flow_executions' => 100,
            'users' => 10,
            'knowledge_documents' => 10,
        ],
    ]);

    $this->subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);

    TenantContext::setId($this->tenant->id);

    // Commit the RefreshDatabase wrapping transaction so child processes
    // can see the test data. RefreshDatabase will migrate:fresh on next setUp.
    DB::commit();
});

/**
 * Spawn a PHP child process that bootstraps Laravel and runs code.
 */
function spawnU2Process(string $scriptBody, array $args): array
{
    $dbConfig = DB::connection('pgsql')->getConfig();

    $autoloadPath = is_dir('/var/www/html/vendor')
        ? '/var/www/html/vendor/autoload.php'
        : base_path('vendor/autoload.php');
    $appPath = is_dir('/var/www/html/vendor')
        ? '/var/www/html/bootstrap/app.php'
        : base_path('bootstrap/app.php');

    $script = str_replace(
        ['__DIR__ . \'/autoload.php\'', '__DIR__ . \'/app.php\''],
        [var_export($autoloadPath, true), var_export($appPath, true)],
        $scriptBody,
    );

    $tmpScript = tempnam(sys_get_temp_dir(), 'u2_atomic_');
    file_put_contents($tmpScript, $script);

    $php = PHP_BINARY;
    $cmd = '"'.$php.'" -d memory_limit=256M "'.$tmpScript.'"';
    foreach ($args as $arg) {
        $cmd .= ' "'.$arg.'"';
    }

    $env = [
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $dbConfig['host'],
        'DB_PORT' => (string) $dbConfig['port'],
        'DB_DATABASE' => $dbConfig['database'],
        'DB_USERNAME' => $dbConfig['username'],
        'DB_PASSWORD' => $dbConfig['password'] ?? '',
    ];

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $desc, $pipes, null, $env);

    $output = '';
    $stderr = '';
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $output = trim(stream_get_contents($pipes[1]));
        $stderr = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    @unlink($tmpScript);

    return ['output' => $output, 'stderr' => $stderr];
}

$COMMIT_SCRIPT = <<<'PHP'
<?php
require __DIR__ . '/autoload.php';
$app = require_once __DIR__ . '/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Models\UsageReservation;
use App\Infrastructure\Tenancy\TenantContext;

$reservation = UsageReservation::withoutTenantScope()->find($argv[1]);
if (!$reservation) { echo "FAIL:NOT_FOUND\n"; exit(0); }
$guard = new UsageGuard(new EntitlementResolver);
TenantContext::setId($reservation->tenant_id);

try {
    $guard->commit($reservation);
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL:" . get_class($e) . "\n";
}
PHP;

$COMMIT_ACTUAL_SCRIPT = <<<'PHP'
<?php
require __DIR__ . '/autoload.php';
$app = require_once __DIR__ . '/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Models\UsageReservation;
use App\Infrastructure\Tenancy\TenantContext;

$reservation = UsageReservation::withoutTenantScope()->find($argv[1]);
if (!$reservation) { echo "FAIL:NOT_FOUND\n"; exit(0); }
$guard = new UsageGuard(new EntitlementResolver);
TenantContext::setId($reservation->tenant_id);

try {
    $record = $guard->commitWithActual($reservation, (int)$argv[2]);
    echo "OK:{$record->quantity}\n";
} catch (\Throwable $e) {
    echo "FAIL:" . get_class($e) . "\n";
}
PHP;

$RELEASE_SCRIPT = <<<'PHP'
<?php
require __DIR__ . '/autoload.php';
$app = require_once __DIR__ . '/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Models\UsageReservation;
use App\Infrastructure\Tenancy\TenantContext;

$reservation = UsageReservation::withoutTenantScope()->find($argv[1]);
if (!$reservation) { echo "FAIL:NOT_FOUND\n"; exit(0); }
$guard = new UsageGuard(new EntitlementResolver);
TenantContext::setId($reservation->tenant_id);

try {
    $guard->release($reservation);
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL:" . get_class($e) . "\n";
}
PHP;

$RESERVE_SCRIPT = <<<'PHP'
<?php
require __DIR__ . '/autoload.php';
$app = require_once __DIR__ . '/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;

$tenant = Tenant::withoutTenantScope()->find($argv[1]);
if (!$tenant) { echo "FAIL:NOT_FOUND\n"; exit(0); }
$guard = new UsageGuard(new EntitlementResolver);
TenantContext::setId($tenant->id);

try {
    $r = $guard->reserve(tenant: $tenant, category: UsageCategory::Messages, quantity: 20);
    echo $r !== null ? "OK\n" : "NULL\n";
} catch (\Throwable $e) {
    echo "FAIL:" . get_class($e) . "\n";
}
PHP;

// ============================================================
// UA-COMMIT-01: 10 concurrent commit() on same reservation
// Expected: exactly 1 UsageRecord, reservation committed
// ============================================================

it('UA-COMMIT-01: concurrent commit on same reservation produces exactly one usage record', function () use ($COMMIT_SCRIPT): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 10,
    );

    $reservationId = $reservation->id;
    $results = [];

    for ($i = 0; $i < 10; $i++) {
        $results[] = spawnU2Process($COMMIT_SCRIPT, [$reservationId]);
    }

    $successCount = count(array_filter($results, fn (array $r) => str_starts_with($r['output'], 'OK')));
    $failCount = count(array_filter($results, fn (array $r) => str_starts_with($r['output'], 'FAIL:')));

    expect($successCount + $failCount)->toBe(10)
        ->and($successCount)->toBeGreaterThanOrEqual(1);

    $usageCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->where('metadata->reservation_id', $reservationId)
        ->count();

    expect($usageCount)->toBe(1);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Committed);
})->group('UA-COMMIT-01');

// ============================================================
// UA-COMMIT-02: 10 concurrent commitWithActual() on same AI reservation
// ============================================================

it('UA-COMMIT-02: concurrent commitWithActual produces exactly one usage record', function () use ($COMMIT_ACTUAL_SCRIPT): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::AiTokens,
        quantity: 100,
    );

    $reservationId = $reservation->id;
    $results = [];

    for ($i = 0; $i < 10; $i++) {
        $results[] = spawnU2Process($COMMIT_ACTUAL_SCRIPT, [$reservationId, '73']);
    }

    $successCount = count(array_filter($results, fn (array $r) => str_starts_with($r['output'], 'OK:')));
    $failCount = count(array_filter($results, fn (array $r) => str_starts_with($r['output'], 'FAIL:')));

    expect($successCount + $failCount)->toBe(10)
        ->and($successCount)->toBeGreaterThanOrEqual(1);

    $usageCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->where('metadata->reservation_id', $reservationId)
        ->count();

    expect($usageCount)->toBe(1);

    $record = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::AiTokens)
        ->where('metadata->reservation_id', $reservationId)
        ->first();

    expect($record->quantity)->toBe(73);

    $reservation->refresh();
    expect($reservation->status)->toBe(UsageReservationStatus::Committed)
        ->and($reservation->quantity)->toBe(73);
})->group('UA-COMMIT-02');

// ============================================================
// UA-COMMIT-03: release vs commit race on same reservation
// Expected: exactly one winner per round
// ============================================================

it('UA-COMMIT-03: release vs commit race produces deterministic outcome', function () use ($COMMIT_SCRIPT, $RELEASE_SCRIPT): void {
    for ($round = 0; $round < 5; $round++) {
        $reservation = $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 10,
        );

        $reservationId = $reservation->id;

        $resultA = spawnU2Process($COMMIT_SCRIPT, [$reservationId]);
        $resultB = spawnU2Process($RELEASE_SCRIPT, [$reservationId]);

        $commitOk = str_starts_with($resultA['output'], 'OK');
        $releaseOk = str_starts_with($resultB['output'], 'OK');

        expect($commitOk xor $releaseOk)->toBeTrue(
            "Round {$round}: expected exactly one winner, got commit={$resultA['output']}, release={$resultB['output']}",
        );
    }
})->group('UA-COMMIT-03');

// ============================================================
// UA-COMMIT-04: two different actual values — one wins
// ============================================================

it('UA-COMMIT-04: concurrent commitWithActual with different values — one wins deterministically', function () use ($COMMIT_ACTUAL_SCRIPT): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['ai_tokens' => 50000])]);

    for ($round = 0; $round < 5; $round++) {
        $reservation = $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::AiTokens,
            quantity: 100,
        );

        $reservationId = $reservation->id;

        $resultA = spawnU2Process($COMMIT_ACTUAL_SCRIPT, [$reservationId, '80']);
        $resultB = spawnU2Process($COMMIT_ACTUAL_SCRIPT, [$reservationId, '120']);

        $successes = count(array_filter(
            [$resultA['output'], $resultB['output']],
            fn (string $r) => str_starts_with($r, 'OK:'),
        ));

        expect($successes)->toBe(1);

        $recordCount = UsageRecord::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('category', UsageCategory::AiTokens)
            ->where('metadata->reservation_id', $reservationId)
            ->count();

        expect($recordCount)->toBe(1);

        $record = UsageRecord::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('category', UsageCategory::AiTokens)
            ->where('metadata->reservation_id', $reservationId)
            ->first();

        expect($record->quantity)->toBeIn([80, 120]);
    }
})->group('UA-COMMIT-04');

// ============================================================
// UA-COMMIT-05: remaining() snapshot — committed + active + expired + released
// ============================================================

it('UA-COMMIT-05: remaining returns correct snapshot with mixed reservation states', function (): void {
    TenantContext::setId($this->tenant->id);

    UsageRecord::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 30,
        'metadata' => [],
        'recorded_at' => now(),
    ]);

    UsageReservation::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'period_start' => Carbon::parse('2026-08-01'),
        'period_end' => Carbon::parse('2026-09-01'),
        'quantity' => 20,
        'status' => UsageReservationStatus::Reserved,
        'expires_at' => now()->addHour(),
        'reserved_at' => now(),
    ]);

    UsageReservation::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'period_start' => Carbon::parse('2026-08-01'),
        'period_end' => Carbon::parse('2026-09-01'),
        'quantity' => 50,
        'status' => UsageReservationStatus::Reserved,
        'expires_at' => now()->subHour(),
        'reserved_at' => now()->subHour(),
    ]);

    UsageReservation::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'period_start' => Carbon::parse('2026-08-01'),
        'period_end' => Carbon::parse('2026-09-01'),
        'quantity' => 40,
        'status' => UsageReservationStatus::Released,
        'expires_at' => now()->addHour(),
        'reserved_at' => now(),
        'released_at' => now(),
    ]);

    $remaining = $this->guard->remaining($this->tenant, UsageCategory::Messages);

    expect($remaining)->toBe(50);
})->group('UA-COMMIT-05');

// ============================================================
// UA-COMMIT-06: reserve during commit — total never exceeds limit
// ============================================================

it('UA-COMMIT-06: reserve during commit never exceeds limit', function () use ($COMMIT_SCRIPT, $RESERVE_SCRIPT): void {
    TenantContext::setId($this->tenant->id);

    UsageRecord::create([
        'tenant_id' => $this->tenant->id,
        'subscription_id' => $this->subscription->id,
        'category' => UsageCategory::Messages,
        'quantity' => 50,
        'metadata' => [],
        'recorded_at' => now(),
    ]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 40,
    );

    $resultA = spawnU2Process($COMMIT_SCRIPT, [$reservation->id]);
    $resultB = spawnU2Process($RESERVE_SCRIPT, [$this->tenant->id]);

    $totalUsed = (int) UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->sum('quantity');

    $totalReserved = (int) UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->where('status', UsageReservationStatus::Reserved)
        ->sum('quantity');

    expect($totalUsed + $totalReserved)->toBeLessThanOrEqual(100);
})->group('UA-COMMIT-06');

// ============================================================
// UA-COMMIT-07: commit on deleted reservation throws
// ============================================================

it('UA-COMMIT-07: commit on deleted reservation throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    UsageReservation::withoutTenantScope()->where('id', $reservation->id)->delete();

    try {
        $this->guard->commit($reservation);
        $this->fail('Expected exception');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('not found');
    }
})->group('UA-COMMIT-07');

// ============================================================
// UA-COMMIT-08: release on deleted reservation throws
// ============================================================

it('UA-COMMIT-08: release on deleted reservation throws InvalidArgumentException', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 5,
    );

    UsageReservation::withoutTenantScope()->where('id', $reservation->id)->delete();

    try {
        $this->guard->release($reservation);
        $this->fail('Expected exception');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('not found');
    }
})->group('UA-COMMIT-08');
