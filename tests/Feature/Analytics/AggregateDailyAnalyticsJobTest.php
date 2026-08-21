<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\AnalyticsDaily;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\AggregateDailyAnalyticsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['timezone' => 'UTC']);
});

/*
|---------------------------------------------------------------------------
| Job tests (AN-JOB-01..08)
|---------------------------------------------------------------------------
*/

it('AN-JOB-01: job dispatches and calls AggregationService', function (): void {
    Bus::fake();

    AggregateDailyAnalyticsJob::dispatch($this->tenant->id, '2026-08-20');

    Bus::assertDispatched(AggregateDailyAnalyticsJob::class, function (AggregateDailyAnalyticsJob $job): bool {
        return $job->tenantId === $this->tenant->id && $job->date === '2026-08-20';
    });
})->group('AN-JOB-01');

it('AN-JOB-02: uniqueId returns correct format', function (): void {
    $job = new AggregateDailyAnalyticsJob($this->tenant->id, '2026-08-20');

    $this->assertEquals(
        "analytics:aggregate:{$this->tenant->id}:2026-08-20",
        $job->uniqueId(),
    );
})->group('AN-JOB-02');

it('AN-JOB-03: uniqueFor returns 300 seconds', function (): void {
    $job = new AggregateDailyAnalyticsJob($this->tenant->id, '2026-08-20');

    $this->assertEquals(300, $job->uniqueFor());
})->group('AN-JOB-03');

it('AN-JOB-04: tries returns 3', function (): void {
    $job = new AggregateDailyAnalyticsJob($this->tenant->id, '2026-08-20');

    $this->assertEquals(3, $job->tries());
})->group('AN-JOB-04');

it('AN-JOB-05: backoff returns exponential delays', function (): void {
    $job = new AggregateDailyAnalyticsJob($this->tenant->id, '2026-08-20');

    $this->assertEquals([30, 60, 120], $job->backoff());
})->group('AN-JOB-05');

it('AN-JOB-06: job with nonexistent tenant returns early without error', function (): void {
    $fakeTenantId = (string) Str::uuid();

    $job = new AggregateDailyAnalyticsJob($fakeTenantId, '2026-08-20');
    $job->handle();

    $this->assertDatabaseCount('analytics_daily', 0);
})->group('AN-JOB-06');

it('AN-JOB-07: job integrates with AggregationService end-to-end', function (): void {
    Queue::fake();

    TenantContext::setId($this->tenant->id);

    $c = Contact::create([
        'name' => 'Test',
        'phone' => '+1234567890',
    ]);
    $convId = (string) Str::uuid();
    $windowTime = Carbon::parse('2026-08-20 10:00');
    DB::table('conversations')->insert([
        'id' => $convId,
        'tenant_id' => $this->tenant->id,
        'contact_id' => $c->id,
        'status' => 'open',
        'bot_paused' => false,
        'auto_assigned' => false,
        'created_at' => $windowTime,
        'updated_at' => $windowTime,
    ]);
    DB::table('messages')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $convId,
        'direction' => 'inbound',
        'type' => 'text',
        'status' => 'sent',
        'body' => 'Hello',
        'created_at' => $windowTime,
        'updated_at' => $windowTime,
    ]);

    $job = new AggregateDailyAnalyticsJob($this->tenant->id, '2026-08-20');
    $job->handle();

    $this->assertDatabaseHas('analytics_daily', [
        'tenant_id' => $this->tenant->id,
        'date' => '2026-08-20',
    ]);

    $daily = AnalyticsDaily::withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('date', '2026-08-20')
        ->first();
    $this->assertEquals(1, $daily->total_messages);
    $this->assertEquals(1, $daily->total_conversations);
})->group('AN-JOB-07');

it('AN-JOB-08: timeout is 300 seconds', function (): void {
    $job = new AggregateDailyAnalyticsJob($this->tenant->id, '2026-08-20');

    $this->assertEquals(300, $job->timeout);
})->group('AN-JOB-08');

/*
|---------------------------------------------------------------------------
| Command tests (AN-CMD-01..05)
|---------------------------------------------------------------------------
*/

it('AN-CMD-01: command dispatches jobs for all tenants', function (): void {
    Bus::fake();

    $tenantB = Tenant::factory()->create(['timezone' => 'America/New_York']);

    $this->artisan('analytics:aggregate-daily', ['--date' => '2026-08-20'])
        ->expectsOutputToContain('Dispatched 2 analytics aggregation jobs.')
        ->assertSuccessful();

    Bus::assertDispatched(AggregateDailyAnalyticsJob::class, 2);
})->group('AN-CMD-01');

it('AN-CMD-02: command dispatches with correct per-tenant dates', function (): void {
    Bus::fake();

    $this->artisan('analytics:aggregate-daily', ['--date' => '2026-08-20'])
        ->assertSuccessful();

    Bus::assertDispatched(AggregateDailyAnalyticsJob::class, function (AggregateDailyAnalyticsJob $job): bool {
        return $job->tenantId === $this->tenant->id && $job->date === '2026-08-20';
    });
})->group('AN-CMD-02');

it('AN-CMD-03: command uses tenant timezone for default date', function (): void {
    Bus::fake();

    $this->artisan('analytics:aggregate-daily')
        ->assertSuccessful();

    Bus::assertDispatched(AggregateDailyAnalyticsJob::class, 1);
})->group('AN-CMD-03');

it('AN-CMD-04: command outputs success with zero tenants', function (): void {
    Tenant::query()->delete();
    Bus::fake();

    $this->artisan('analytics:aggregate-daily')
        ->expectsOutput('No tenants found.')
        ->assertSuccessful();

    Bus::assertNotDispatched(AggregateDailyAnalyticsJob::class);
})->group('AN-CMD-04');

it('AN-CMD-05: command dispatches on analytics queue', function (): void {
    Queue::fake();

    $this->artisan('analytics:aggregate-daily', ['--date' => '2026-08-20'])
        ->assertSuccessful();

    Queue::assertPushedOn('analytics', AggregateDailyAnalyticsJob::class);
})->group('AN-CMD-05');
