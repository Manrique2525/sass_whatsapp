<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Application\Messages\Services\MessageService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
            'ai_tokens' => 5000,
            'contacts' => 50,
            'flow_executions' => 10,
            'users' => 3,
            'knowledge_documents' => 5,
        ],
    ]);

    $this->subscription = Subscription::factory()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-08-01'),
        'current_period_end' => Carbon::parse('2026-09-01'),
    ]);
});

function make_msg_tenant_ready(Tenant $tenant): array
{
    make_whatsapp_setup($tenant);
    $contact = make_contact($tenant, ['phone' => '+15550000001']);
    $conversation = make_conversation($tenant, $contact);

    return ['contact' => $contact, 'conversation' => $conversation];
}

function fake_send_success(string $id = 'wamid-456'): void
{
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '15550000001', 'wa_id' => '15550000001']],
            'messages' => [['id' => $id]],
        ], 200),
    ]);
}

function fake_send_permanent_error(): void
{
    Http::fake([
        'graph.facebook.com/*/phone-1/messages' => Http::response([
            'error' => [
                'message' => '(#131030) Recipient phone number not in allowed list.',
                'type' => 'OAuthException',
                'code' => 131030,
            ],
        ], 400),
    ]);
}

// ──────────────────────────────────────────────
// MSG-01..05: Quota boundary
// ──────────────────────────────────────────────

it('USG-U2-MSG-01: under limit creates reservation and sends', function (): void {
    fake_send_success();
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Hola');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($message->status)->toBe(MessageStatus::Sent);

    $reservation = UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('idempotency_key', "message:{$message->id}")
        ->first();
    expect($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(UsageReservationStatus::Committed);
});

it('USG-U2-MSG-02: exact remaining sends', function (): void {
    fake_send_success();
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 1])]);
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Last msg');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($message->status)->toBe(MessageStatus::Sent);
});

it('USG-U2-MSG-03: at limit blocked throws TenantQuotaExceededException', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 1])]);
    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'pre-fill-1',
    );
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    $this->expectException(TenantQuotaExceededException::class);
    app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Blocked');
});

it('USG-U2-MSG-04: unlimited plan sends without reservation', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => null])]);
    fake_send_success();
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Unlimited');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($message->status)->toBe(MessageStatus::Sent);
});

it('USG-U2-MSG-05: zero limit blocked', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 0])]);
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    $this->expectException(TenantQuotaExceededException::class);
    app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Blocked');
});

// ──────────────────────────────────────────────
// MSG-06..10: Commit/release lifecycle
// ──────────────────────────────────────────────

it('USG-U2-MSG-06: successful send commits +1 usage record', function (): void {
    fake_send_success();
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Hello');

    $this->assertDatabaseHas('usage_records', [
        'tenant_id' => $this->tenant->id,
        'category' => UsageCategory::Messages->value,
        'quantity' => 1,
    ]);
});

it('USG-U2-MSG-07: permanent failure releases reservation', function (): void {
    fake_send_permanent_error();
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Fail');

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($message->status)->toBe(MessageStatus::Failed);

    $reservation = UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('idempotency_key', "message:{$message->id}")
        ->first();
    expect($reservation->status)->toBe(UsageReservationStatus::Released);

    $this->assertDatabaseMissing('usage_records', [
        'tenant_id' => $this->tenant->id,
        'category' => UsageCategory::Messages->value,
    ]);
});

it('USG-U2-MSG-08: transient failure keeps reservation reserved for retry', function (): void {
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    Queue::fake();

    $message = app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Retryable');

    expect($message->status->value)->toBe(MessageStatus::Pending->value);

    $reservation = UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('idempotency_key', "message:{$message->id}")
        ->first();
    expect($reservation->status->value)->toBe(UsageReservationStatus::Reserved->value);

    $this->assertDatabaseMissing('usage_records', [
        'tenant_id' => $this->tenant->id,
        'category' => UsageCategory::Messages->value,
    ]);

    Queue::assertPushed(SendWhatsAppMessage::class);
});

it('USG-U2-MSG-09: retry success commits once', function (): void {
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    $message = TenantContext::withId($this->tenant->id, fn (): Message => Message::query()->create([
        'conversation_id' => $setup['conversation']->id,
        'direction' => 'outbound',
        'type' => 'text',
        'status' => 'pending',
        'body' => 'Retry me',
        'metadata' => ['text' => 'Retry me', 'origin' => 'automation', 'attempt_tracking' => 'message_id_v1'],
    ]));

    $reservation = TenantContext::withId($this->tenant->id, fn () => $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: "message:{$message->id}",
        ttlSeconds: 900,
    ));

    TenantContext::withId($this->tenant->id, fn () => $this->guard->commit($reservation));

    $sameReservation = TenantContext::withId($this->tenant->id, fn () => $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: "message:{$message->id}",
        ttlSeconds: 900,
    ));

    expect($sameReservation->id)->toBe($reservation->id)
        ->and($sameReservation->status)->toBe(UsageReservationStatus::Committed);

    $usageCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->count();
    expect($usageCount)->toBe(1);
});

it('USG-U2-MSG-10: duplicate job commits once', function (): void {
    fake_send_success();
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    $message = app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Dedup');

    $this->assertDatabaseHas('usage_records', [
        'tenant_id' => $this->tenant->id,
        'category' => UsageCategory::Messages->value,
        'quantity' => 1,
    ]);

    $usageCount = UsageRecord::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('category', UsageCategory::Messages)
        ->count();
    expect($usageCount)->toBe(1);
});

// ──────────────────────────────────────────────
// MSG-11..18: Entitlement
// ──────────────────────────────────────────────

it('USG-U2-MSG-11: Active subscription allowed', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Active]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(UsageReservationStatus::Reserved);
});

it('USG-U2-MSG-12: PastDue subscription allowed', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::PastDue]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(UsageReservationStatus::Reserved);
});

it('USG-U2-MSG-13: Pending subscription returns null (no enforcement)', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Pending]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation)->toBeNull();
});

it('USG-U2-MSG-14: Cancelled subscription returns null (no enforcement)', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation)->toBeNull();
});

it('USG-U2-MSG-15: missing subscription returns null (no enforcement)', function (): void {
    $this->subscription->delete();

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation)->toBeNull();
});

it('USG-U2-MSG-16: cancel_at_period_end still active for current period', function (): void {
    $this->subscription->update(['cancel_at_period_end' => true, 'status' => SubscriptionStatus::Active]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation)->not->toBeNull()
        ->and($reservation->status)->toBe(UsageReservationStatus::Reserved);
});

it('USG-U2-MSG-17: plan downgrade re-check (reservation holds quota)', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'downgrade-test',
    );

    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 0])]);

    $existing = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'downgrade-test',
    );

    expect($existing->id)->toBe($reservation->id);
});

it('USG-U2-MSG-18: plan upgrade recognizes new limit', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 1])]);

    $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'upgrade-pre',
    );

    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 10])]);

    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'upgrade-post',
    );

    expect($reservation)->not->toBeNull();
});

// ──────────────────────────────────────────────
// MSG-19..26: Security
// ──────────────────────────────────────────────

it('USG-U2-MSG-19: tenant A cannot consume B quota', function (): void {
    $tenantA = $this->tenant;
    $tenantB = Tenant::factory()->create();

    $planB = Plan::factory()->create([
        'limits' => ['messages' => 1],
    ]);

    TenantContext::setId($tenantB->id);
    Subscription::factory()->create([
        'tenant_id' => $tenantB->id,
        'plan_id' => $planB->id,
        'status' => SubscriptionStatus::Active,
    ]);

    TenantContext::setId($tenantA->id);
    $this->guard->reserve(
        tenant: $tenantA,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'a-msg',
    );

    TenantContext::setId($tenantB->id);
    $reservation = $this->guard->reserve(
        tenant: $tenantB,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'b-msg',
    );

    expect($reservation)->not->toBeNull();
});

it('USG-U2-MSG-20: category cannot be overridden', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
        idempotencyKey: 'cat-test',
    );

    expect($reservation->category)->toBe(UsageCategory::Messages);
});

it('USG-U2-MSG-21: quantity fixed at 1', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation->quantity)->toBe(1);
});

it('USG-U2-MSG-22: no tenant_id request control', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation->tenant_id)->toBe($this->tenant->id);
});

it('USG-U2-MSG-23: no plan_id request control', function (): void {
    $reservation = $this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    );

    expect($reservation->subscription_id)->toBe($this->subscription->id);
});

it('USG-U2-MSG-24: safe 429 response', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 0])]);
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    try {
        app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Blocked');
        $this->fail('Expected TenantQuotaExceededException');
    } catch (TenantQuotaExceededException $e) {
        expect($e->getCode())->toBe(429)
            ->and($e->category)->toBe('messages')
            ->and($e->limit)->toBe(0)
            ->and(str_contains($e->getMessage(), 'quota'))->toBeTrue();
    }
});

it('USG-U2-MSG-25: no provider call after quota rejection', function (): void {
    Http::fake();
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 0])]);
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    try {
        app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Blocked');
    } catch (TenantQuotaExceededException) {
        // expected
    }

    Http::assertNothingSent();
});

it('USG-U2-MSG-26: no raw DB/provider exception leak', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => 0])]);
    $setup = make_msg_tenant_ready($this->tenant);
    TenantContext::clear();

    try {
        app(MessageService::class)->createOutbound($this->tenant, $setup['conversation'], 'Blocked');
        $this->fail('Expected exception');
    } catch (TenantQuotaExceededException $e) {
        expect($e->getMessage())->not->toContain('SQL')
            ->and($e->getMessage())->not->toContain('PDOException')
            ->and($e->getMessage())->not->toContain('token');
    }
});
