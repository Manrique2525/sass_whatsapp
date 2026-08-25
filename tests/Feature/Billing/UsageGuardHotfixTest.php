<?php

declare(strict_types=1);

use App\Application\Billing\Guards\EntitlementResolver;
use App\Application\Billing\Guards\UsageGuard;
use App\Application\Flows\Services\FlowExecutionService;
use App\Application\Messages\Services\MessageService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Models\FlowExecution;
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

    make_whatsapp_setup($this->tenant);
    $this->contact = make_contact($this->tenant, ['phone' => '+15550000001']);
    $this->conversation = make_conversation($this->tenant, $this->contact);

    $this->chatbot = make_chatbot($this->tenant);
    $this->flow = make_flow($this->tenant, $this->chatbot);
    make_flow_graph($this->flow, [
        ['id' => 'start', 'type' => 'message', 'name' => 'Start', 'config' => ['text' => 'Hi'], 'is_start' => true],
        ['id' => 'end', 'type' => 'end', 'name' => 'End'],
    ], [
        ['from' => 'start', 'to' => 'end'],
    ]);
    $this->flow->forceFill(['status' => FlowStatus::Published->value])->save();
    $this->flow->refresh();

    TenantContext::clear();
});

it('USG-U2-HF-MSG-01: missing subscription blocks outbound before message persistence', function (): void {
    Queue::fake();
    $this->subscription->delete();

    expect(fn () => app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Blocked',
    ))->toThrow(SubscriptionNotFoundException::class);

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('USG-U2-HF-MSG-02: missing subscription never calls the provider', function (): void {
    Http::fake();
    $this->subscription->delete();

    expect(fn () => app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Blocked',
    ))->toThrow(SubscriptionNotFoundException::class);

    Http::assertNothingSent();
});

it('USG-U2-HF-MSG-03: missing subscription never dispatches the send job', function (): void {
    Queue::fake();
    $this->subscription->delete();

    expect(fn () => app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Blocked',
    ))->toThrow(SubscriptionNotFoundException::class);

    Queue::assertNothingPushed();
});

it('USG-U2-HF-MSG-04: active unlimited subscription creates and queues outbound', function (): void {
    Queue::fake();
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => null])]);

    $message = app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Unlimited',
    );

    expect($message->status)->toBe(MessageStatus::Pending)
        ->and(UsageReservation::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Queue::assertPushed(SendWhatsAppMessage::class);
});

it('USG-U2-HF-MSG-05: past-due unlimited subscription creates and queues outbound', function (): void {
    Queue::fake();
    $this->subscription->update(['status' => SubscriptionStatus::PastDue]);
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => null])]);

    $message = app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Past due unlimited',
    );

    expect($message->status)->toBe(MessageStatus::Pending)
        ->and(UsageReservation::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Queue::assertPushed(SendWhatsAppMessage::class);
});

it('USG-U2-HF-MSG-06: pending subscription blocks outbound', function (): void {
    Queue::fake();
    $this->subscription->update(['status' => SubscriptionStatus::Pending]);

    expect(fn () => app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Blocked',
    ))->toThrow(SubscriptionNotFoundException::class);

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('USG-U2-HF-MSG-07: cancelled subscription blocks outbound', function (): void {
    Queue::fake();
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled]);

    expect(fn () => app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Blocked',
    ))->toThrow(SubscriptionNotFoundException::class);

    expect(Message::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('USG-U2-HF-JOB-01: subscription removed after enqueue prevents worker send', function (): void {
    Queue::fake();
    $message = app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Queued before removal',
    );
    $this->subscription->delete();
    Http::fake();

    $job = new SendWhatsAppMessage($this->tenant->id, $this->conversation->id, $message->id);

    expect(fn () => $job->handle())->toThrow(SubscriptionNotFoundException::class);
    Http::assertNothingSent();
});

it('USG-U2-HF-JOB-02: inactive subscription prevents worker provider call', function (): void {
    Queue::fake();
    $message = app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Queued before suspension',
    );
    $this->subscription->update(['status' => SubscriptionStatus::Pending]);
    Http::fake();

    $job = new SendWhatsAppMessage($this->tenant->id, $this->conversation->id, $message->id);

    expect(fn () => $job->handle())->toThrow(SubscriptionNotFoundException::class);
    Http::assertNothingSent();
});

it('USG-U2-HF-JOB-03: exhausted entitlement rejection terminates message and releases reservation', function (): void {
    Queue::fake();
    $message = app(MessageService::class)->createOutbound(
        $this->tenant,
        $this->conversation,
        'Queued before cancellation',
    );
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled]);
    Http::fake();

    $job = new SendWhatsAppMessage($this->tenant->id, $this->conversation->id, $message->id);
    $exception = null;

    try {
        $job->handle();
    } catch (SubscriptionNotFoundException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(SubscriptionNotFoundException::class)
        ->and($job->tries())->toBeGreaterThan(0)->toBeLessThan(100);

    $job->failed($exception);

    $reservation = UsageReservation::query()
        ->withoutTenantScope()
        ->where('tenant_id', $this->tenant->id)
        ->where('idempotency_key', "message:{$message->id}")
        ->firstOrFail();

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($reservation->fresh()->status)->toBe(UsageReservationStatus::Released);
    Http::assertNothingSent();
});

it('USG-U2-HF-FLOW-01: missing subscription blocks flow before execution persistence', function (): void {
    $this->subscription->delete();
    TenantContext::setId($this->tenant->id);

    expect(fn () => app(FlowExecutionService::class)->start(
        $this->flow,
        $this->conversation,
    ))->toThrow(SubscriptionNotFoundException::class);

    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('USG-U2-HF-FLOW-02: active unlimited subscription starts flow without reservation', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['flow_executions' => null])]);
    TenantContext::setId($this->tenant->id);

    $execution = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    expect($execution)->not->toBeNull()
        ->and(UsageReservation::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('category', UsageCategory::FlowExecutions)
            ->count())->toBe(0);
});

it('USG-U2-HF-FLOW-03: past-due subscription starts flow', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::PastDue]);
    TenantContext::setId($this->tenant->id);

    $execution = app(FlowExecutionService::class)->start($this->flow, $this->conversation);

    expect($execution)->not->toBeNull();
});

it('USG-U2-HF-FLOW-04: pending subscription blocks flow', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Pending]);
    TenantContext::setId($this->tenant->id);

    expect(fn () => app(FlowExecutionService::class)->start(
        $this->flow,
        $this->conversation,
    ))->toThrow(SubscriptionNotFoundException::class);

    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('USG-U2-HF-FLOW-05: cancelled subscription blocks flow', function (): void {
    $this->subscription->update(['status' => SubscriptionStatus::Cancelled]);
    TenantContext::setId($this->tenant->id);

    expect(fn () => app(FlowExecutionService::class)->start(
        $this->flow,
        $this->conversation,
    ))->toThrow(SubscriptionNotFoundException::class);

    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('USG-U2-HF-SEC-01: null entitlement result is exclusive to an unlimited active plan', function (): void {
    $this->plan->update(['limits' => array_merge($this->plan->limits, ['messages' => null])]);

    expect($this->guard->reserve(
        tenant: $this->tenant,
        category: UsageCategory::Messages,
        quantity: 1,
    ))->toBeNull();

    $this->subscription->delete();

    expect(fn () => $this->guard->remaining(
        $this->tenant,
        UsageCategory::Messages,
    ))->toThrow(SubscriptionNotFoundException::class)
        ->and(fn () => $this->guard->reserve(
            tenant: $this->tenant,
            category: UsageCategory::Messages,
            quantity: 1,
        ))->toThrow(SubscriptionNotFoundException::class);
});
