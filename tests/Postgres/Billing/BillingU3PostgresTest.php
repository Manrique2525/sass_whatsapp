<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\WebhookEventStatus;
use App\Domain\Billing\Models\BillingWebhookEvent;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('BILL-U3-PG-01: unique provider+provider_event_id constraint enforced', function (): void {
    BillingWebhookEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_pg_unique_001',
        'type' => 'checkout.session.completed',
        'status' => WebhookEventStatus::Processed,
    ]);

    // Second insert with same provider+event_id should fail
    try {
        DB::table('billing_webhook_events')->insert([
            'id' => DB::raw('gen_random_uuid()'),
            'provider' => 'stripe',
            'provider_event_id' => 'evt_pg_unique_001',
            'type' => 'checkout.session.completed',
            'status' => WebhookEventStatus::Pending,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fail('Expected unique constraint violation');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('billing_webhook_events_provider_event_unique');
    }
})->group('BILL-U3-PG-01');

it('BILL-U3-PG-02: different providers allow same event ID', function (): void {
    BillingWebhookEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_pg_multi_001',
        'type' => 'checkout.session.completed',
        'status' => WebhookEventStatus::Processed,
    ]);

    // Same event ID but different provider should succeed
    BillingWebhookEvent::create([
        'provider' => 'other_provider',
        'provider_event_id' => 'evt_pg_multi_001',
        'type' => 'checkout.session.completed',
        'status' => WebhookEventStatus::Processed,
    ]);

    $count = BillingWebhookEvent::where('provider_event_id', 'evt_pg_multi_001')->count();
    expect($count)->toBe(2);
})->group('BILL-U3-PG-02');

it('BILL-U3-PG-03: provider_updated_at column exists on subscriptions', function (): void {
    $columns = DB::getSchemaBuilder()->getColumnListing('subscriptions');
    expect($columns)->toContain('provider_updated_at');
})->group('BILL-U3-PG-03');

it('BILL-U3-PG-04: provider_updated_at stores timezone-aware datetime', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();

    TenantContext::setId($tenant->id);

    $sub = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'stripe_subscription_id' => 'sub_pg_tz_001',
        'status' => SubscriptionStatus::Active,
        'quantity' => 1,
        'provider_updated_at' => '2026-08-24 12:00:00',
    ]);

    expect($sub->provider_updated_at)->not->toBeNull();
    expect($sub->provider_updated_at->timestamp)->toBeGreaterThan(0);
})->group('BILL-U3-PG-04');

it('BILL-U3-PG-05: webhook event tenant_id nullable', function (): void {
    $event = BillingWebhookEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_pg_nullable_001',
        'type' => 'checkout.session.completed',
        'status' => WebhookEventStatus::Pending,
        'tenant_id' => null,
    ]);

    expect($event->tenant_id)->toBeNull();
})->group('BILL-U3-PG-05');

it('BILL-U3-PG-06: error_code nullable on webhook events', function (): void {
    $event = BillingWebhookEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_pg_err_001',
        'type' => 'checkout.session.completed',
        'status' => WebhookEventStatus::Processed,
        'error_code' => null,
    ]);

    expect($event->error_code)->toBeNull();
})->group('BILL-U3-PG-06');
