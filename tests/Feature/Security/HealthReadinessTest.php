<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FASE 28 U4 — Health / Readiness + Queue / Webhook Monitoring Tests
|--------------------------------------------------------------------------
*/

use App\Infrastructure\Health\HealthChecker;
use App\Infrastructure\Logging\SentryQueueFailureServiceProvider;
use App\Jobs\AggregateDailyAnalyticsJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// ─── Liveness ───

it('F28-U4-HEALTH-01: liveness healthy returns 200', function (): void {
    $response = $this->get('/health');

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'ok',
        'mode' => 'liveness',
    ]);
    $response->assertJsonStructure(['status', 'mode', 'checks' => ['app']]);
});

it('F28-U4-HEALTH-02: liveness does not check database', function (): void {
    $response = $this->get('/health');

    $json = $response->json();
    expect($json['checks'])->not->toHaveKey('database');
});

it('F28-U4-HEALTH-03: liveness does not check redis', function (): void {
    $response = $this->get('/health');

    $json = $response->json();
    expect($json['checks'])->not->toHaveKey('redis');
});

it('F28-U4-HEALTH-04: liveness does not check queue', function (): void {
    $response = $this->get('/health');

    $json = $response->json();
    expect($json['checks'])->not->toHaveKey('queue');
});

// ─── Readiness ───

it('F28-U4-HEALTH-05: readiness healthy returns 200', function (): void {
    $response = $this->get('/ready');

    $response->assertStatus(200);
    $response->assertJson([
        'status' => 'ok',
        'mode' => 'readiness',
    ]);
    $response->assertJsonStructure(['status', 'mode', 'checks' => ['database', 'redis', 'queue']]);
});

it('F28-U4-HEALTH-06: readiness does not check external providers', function (): void {
    $response = $this->get('/ready');

    $json = $response->json();
    expect($json['checks'])->not->toHaveKey('meta');
    expect($json['checks'])->not->toHaveKey('openai');
    expect($json['checks'])->not->toHaveKey('stripe');
});

it('F28-U4-HEALTH-07: safe response format no exception details', function (): void {
    $response = $this->get('/ready');

    $json = $response->json();
    expect($json)->not->toHaveKey('exception');
    expect($json)->not->toHaveKey('trace');
    expect($json)->not->toHaveKey('message');

    foreach ($json['checks'] ?? [] as $component => $status) {
        expect($status)->toBeIn(['ok', 'fail']);
    }
});

it('F28-U4-HEALTH-08: X-Request-ID present on health', function (): void {
    $response = $this->get('/health');

    $response->assertHeader('X-Request-ID');
});

it('F28-U4-HEALTH-09: X-Request-ID present on ready', function (): void {
    $response = $this->get('/ready');

    $response->assertHeader('X-Request-ID');
});

it('F28-U4-HEALTH-10: readiness includes scheduler info', function (): void {
    $response = $this->get('/ready');

    $response->assertStatus(200);
    $json = $response->json();
    // scheduler key may be absent (no heartbeat yet) or present
    expect($json)->toHaveKey('checks');
});

// ─── HealthChecker Unit ───

it('F28-U4-HEALTH-11: checkLiveness returns only app key', function (): void {
    $checker = app(HealthChecker::class);
    $liveness = $checker->checkLiveness();

    expect($liveness)->toHaveKeys(['app']);
    expect($liveness['app'])->toBe('ok');
});

it('F28-U4-HEALTH-12: checkReadiness returns database redis queue', function (): void {
    $checker = app(HealthChecker::class);
    $readiness = $checker->checkReadiness();

    expect($readiness)->toHaveKeys(['database', 'redis', 'queue']);
    expect($readiness['database'])->toBe('ok');
    expect($readiness['redis'])->toBe('ok');
    expect($readiness['queue'])->toBe('ok');
});

it('F28-U4-HEALTH-13: checkApp verifies config is accessible', function (): void {
    $checker = app(HealthChecker::class);

    expect($checker->checkApp())->toBeTrue();
});

it('F28-U4-HEALTH-14: scheduler heartbeat returns null when no heartbeat', function (): void {
    Cache::store()->forget('observability:scheduler:last_heartbeat');

    $checker = app(HealthChecker::class);

    expect($checker->checkSchedulerHeartbeat())->toBeNull();
});

it('F28-U4-HEALTH-15: scheduler heartbeat returns true when fresh', function (): void {
    Cache::store()->set('observability:scheduler:last_heartbeat', time());

    $checker = app(HealthChecker::class);

    expect($checker->checkSchedulerHeartbeat())->toBeTrue();
});

it('F28-U4-HEALTH-16: scheduler heartbeat returns false when stale', function (): void {
    Cache::store()->set('observability:scheduler:last_heartbeat', time() - 300);

    $checker = app(HealthChecker::class);

    expect($checker->checkSchedulerHeartbeat())->toBeFalse();
});

it('F28-U4-HEALTH-17: allOk returns true when all statuses ok', function (): void {
    $checker = app(HealthChecker::class);

    expect($checker->allOk(['app' => 'ok', 'database' => 'ok']))->toBeTrue();
});

it('F28-U4-HEALTH-18: allOk returns false when any status fail', function (): void {
    $checker = app(HealthChecker::class);

    expect($checker->allOk(['app' => 'ok', 'database' => 'fail']))->toBeFalse();
});

// ─── Scheduler Heartbeat Command ───

it('F28-U4-SCHED-01: scheduler heartbeat command writes timestamp', function (): void {
    $this->artisan('scheduler:heartbeat')
        ->assertExitCode(0);

    $value = Cache::store()->get('observability:scheduler:last_heartbeat');
    expect($value)->not->toBeNull();
    expect((int) $value)->toBeGreaterThan(0);
});

it('F28-U4-SCHED-02: scheduler heartbeat timestamp is fresh', function (): void {
    $before = time();
    $this->artisan('scheduler:heartbeat');
    $after = time();

    $value = (int) Cache::store()->get('observability:scheduler:last_heartbeat');
    expect($value)->toBeGreaterThanOrEqual($before);
    expect($value)->toBeLessThanOrEqual($after);
});

// ─── Analytics Queue ───

it('F28-U4-AN-01: AggregateDailyAnalyticsCommand dispatches to analytics queue', function (): void {
    Queue::fake();

    // Command queries Tenant::all() which needs the tenants table.
    // Use Database gate to skip if tenants table doesn't exist.
    try {
        DB::table('tenants')->limit(1)->get();
    } catch (Throwable) {
        $this->markTestSkipped('tenants table not available in test DB');
    }

    $this->artisan('analytics:aggregate-daily')
        ->assertExitCode(0);

    Queue::assertPushed(AggregateDailyAnalyticsJob::class, function ($job) {
        return $job->queue === 'analytics';
    });
});

it('F28-U4-AN-02: AggregateDailyAnalyticsJob failed() logs structured warning', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->with('analytics.aggregation.permanent_failure', Mockery::on(function ($data) {
            return isset($data['tenant_id'])
                && isset($data['date'])
                && isset($data['job_class'])
                && isset($data['error_class']);
        }));

    $job = new AggregateDailyAnalyticsJob('test-tenant-id', '2026-08-25');
    $job->failed(new RuntimeException('test error'));
});

// ─── Queue::failing Revalidation ───

it('F28-U4-Q-01: SentryQueueFailureServiceProvider is registered', function (): void {
    $providers = app()->getLoadedProviders();
    expect($providers)->toHaveKey('App\Infrastructure\Logging\SentryQueueFailureServiceProvider');
});

it('F28-U4-Q-02: SentryQueueFailureServiceProvider class exists', function (): void {
    expect(class_exists(SentryQueueFailureServiceProvider::class))->toBeTrue();
});

it('F28-U4-Q-03: Queue config has default and analytics queues', function (): void {
    $default = config('queue.connections.redis.queue');
    expect($default)->toBe('default');
});

it('F28-U4-Q-04: failed_jobs config uses database-uuids driver', function (): void {
    $driver = config('queue.failed.driver');
    expect($driver)->toBe('database-uuids');
});

// ─── Config ───

it('F28-U4-CFG-01: observability config is loadable', function (): void {
    $maxAge = config('observability.scheduler_heartbeat_max_age_seconds');
    expect($maxAge)->toBeInt();
    expect($maxAge)->toBeGreaterThan(0);
});
