<?php

declare(strict_types=1);

use App\Application\Messages\Services\MessageService;
use App\Domain\Billing\Contracts\CapacityCheckInterface;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Enums\WebhookEventType;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\ProcessWhatsAppStatusUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCapacityGuard;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(CapacityGuardInterface::class, new FakeCapacityGuard);
});

/*
|--------------------------------------------------------------------------
| FASE 26 U3 — WhatsApp Job Hardening
|--------------------------------------------------------------------------
|
| P1-5: ProcessWhatsAppStatusUpdate failed() handling
| P2-2: ProcessIncomingWhatsAppMessage explicit timeout
|
*/

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function u3_create_status_event(Tenant $tenant, string $statusPayloadId): WebhookEvent
{
    TenantContext::setId($tenant->id);

    try {
        return WebhookEvent::query()->create([
            'provider_event_id' => 'evt-status-'.uniqid('', true),
            'tenant_id' => $tenant->id,
            'status' => WebhookEventStatus::Enqueued,
            'event_type' => WebhookEventType::Status,
            'payload' => [
                'data' => [
                    'id' => $statusPayloadId,
                    'status' => 'delivered',
                    'timestamp' => '1725000000',
                ],
            ],
        ]);
    } finally {
        TenantContext::clear();
    }
}

function u3_create_inbound_event(Tenant $tenant): WebhookEvent
{
    TenantContext::setId($tenant->id);

    try {
        return WebhookEvent::query()->create([
            'provider_event_id' => 'evt-inbound-'.uniqid('', true),
            'tenant_id' => $tenant->id,
            'status' => WebhookEventStatus::Enqueued,
            'event_type' => WebhookEventType::Message,
            'payload' => [
                'data' => [
                    'id' => 'wamid-inbound-'.uniqid('', true),
                    'from' => '15550000001',
                    'timestamp' => '1725000000',
                    'type' => 'text',
                    'text' => ['body' => 'Hola'],
                ],
            ],
        ]);
    } finally {
        TenantContext::clear();
    }
}

// ---------------------------------------------------------------------------
// F26-U3-STAT-01..08 — ProcessWhatsAppStatusUpdate structural + behavioral
// ---------------------------------------------------------------------------

test('F26-U3-STAT-01: ProcessWhatsAppStatusUpdate has explicit timeout = 60', function (): void {
    $job = new ProcessWhatsAppStatusUpdate('evt-1');

    expect($job->timeout)->toBe(60);
});

test('F26-U3-STAT-02: ProcessWhatsAppStatusUpdate has tries = 3', function (): void {
    $job = new ProcessWhatsAppStatusUpdate('evt-1');

    expect($job->tries)->toBe(3);
});

test('F26-U3-STAT-03: ProcessWhatsAppStatusUpdate has backoff [5, 15, 60]', function (): void {
    $job = new ProcessWhatsAppStatusUpdate('evt-1');

    expect($job->backoff())->toBe([5, 15, 60]);
});

test('F26-U3-STAT-04: ProcessWhatsAppStatusUpdate implements failed() method', function (): void {
    $job = new ProcessWhatsAppStatusUpdate('evt-1');

    expect(method_exists($job, 'failed'))->toBeTrue();
});

test('F26-U3-STAT-05: failed() marks Enqueued event as failed with job_exhausted', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u3_create_status_event($tenant, 'wamid-status-05');

    expect($event->status)->toBe(WebhookEventStatus::Enqueued);

    $job = new ProcessWhatsAppStatusUpdate($event->id);
    $job->failed(new RuntimeException('queue exhausted'));

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->error_code)->toBe('job_exhausted')
        ->and($event->processed_at)->not->toBeNull();
});

test('F26-U3-STAT-06: failed() is idempotent for already Processed event', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u3_create_status_event($tenant, 'wamid-status-06');

    $event->markProcessed();

    $originalProcessedAt = $event->processed_at;

    $job = new ProcessWhatsAppStatusUpdate($event->id);
    $job->failed(new RuntimeException('queue exhausted'));

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Processed)
        ->and($event->error_code)->toBeNull()
        ->and($event->processed_at->eq($originalProcessedAt))->toBeTrue();
});

test('F26-U3-STAT-07: failed() is idempotent for already Failed event', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u3_create_status_event($tenant, 'wamid-status-07');

    $event->markFailed('original_reason');

    $job = new ProcessWhatsAppStatusUpdate($event->id);
    $job->failed(new RuntimeException('queue exhausted'));

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->error_code)->toBe('original_reason');
});

test('F26-U3-STAT-08: failed() handles null event gracefully', function (): void {
    $job = new ProcessWhatsAppStatusUpdate('non-existent-event-id');

    $job->failed(new RuntimeException('queue exhausted'));

    // No exception thrown — the event was not found, so failed() is a no-op.
    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// F26-U3-LIFECYCLE-01 — Full failed lifecycle
// ---------------------------------------------------------------------------

test('F26-U3-LIFECYCLE-01: full failed lifecycle marks event as job_exhausted', function (): void {
    $tenant = Tenant::factory()->create();
    $event = u3_create_status_event($tenant, 'wamid-lifecycle');

    expect($event->status)->toBe(WebhookEventStatus::Enqueued);

    // Simulate the queue worker lifecycle: after retries are exhausted, failed() is called.
    // In production the queue worker invokes failed() after $tries attempts.
    // We test the contract: an Enqueued event + a RuntimeException → event marked Failed.
    $job = new ProcessWhatsAppStatusUpdate($event->id);
    $job->failed(new RuntimeException('DB connection lost after 3 attempts'));

    $event->refresh();

    expect($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->error_code)->toBe('job_exhausted')
        ->and($event->processed_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// F26-U3-ORDER-01 — Status ordering regression
// ---------------------------------------------------------------------------

test('F26-U3-ORDER-01: delivered then read on same message preserves correct final status', function (): void {
    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    $contact = make_contact($tenant, ['phone' => '+15550000099']);
    $conversation = make_conversation($tenant, $contact);

    TenantContext::setId($tenant->id);

    try {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'wamid-order-test',
            'direction' => 'outbound',
            'type' => 'text',
            'status' => 'sent',
            'body' => 'Test ordering',
            'sent_at' => now(),
        ]);
    } finally {
        TenantContext::clear();
    }

    // First status update: delivered
    $service = app(MessageService::class);

    $service->handleStatusUpdate($tenant, [
        'id' => 'wamid-order-test',
        'status' => 'delivered',
        'timestamp' => '1725000000',
    ]);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Delivered)
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->read_at)->toBeNull();

    // Second status update: read
    $service->handleStatusUpdate($tenant, [
        'id' => 'wamid-order-test',
        'status' => 'read',
        'timestamp' => '1725000001',
    ]);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Read)
        ->and($message->delivered_at)->not->toBeNull()
        ->and($message->read_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// F26-U3-IN-01 — ProcessIncomingWhatsAppMessage structural
// ---------------------------------------------------------------------------

test('F26-U3-IN-01: ProcessIncomingWhatsAppMessage has explicit timeout = 60', function (): void {
    $job = new ProcessIncomingWhatsAppMessage('evt-1');

    expect($job->timeout)->toBe(60);
});

test('F26-U3-IN-01b: ProcessIncomingWhatsAppMessage has tries = 3', function (): void {
    $job = new ProcessIncomingWhatsAppMessage('evt-1');

    expect($job->tries)->toBe(3);
});

test('F26-U3-IN-01c: ProcessIncomingWhatsAppMessage has backoff [5, 15, 60]', function (): void {
    $job = new ProcessIncomingWhatsAppMessage('evt-1');

    expect($job->backoff())->toBe([5, 15, 60]);
});

// ---------------------------------------------------------------------------
// F26-U3-IN-02 — Incoming processing regression
// ---------------------------------------------------------------------------

test('F26-U3-IN-02: inbound message processing still works after timeout hardening', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(whatsapp_webhook_payload('wamid-regression', 'phone-1'))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'wamid-regression')->firstOrFail();

    expect($event->status->value)->toBe('processed');

    $message = Message::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('provider_message_id', 'wamid-regression')
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->status)->toBe(MessageStatus::Delivered);
});

// ---------------------------------------------------------------------------
// F26-U3-QUOTA-01 — Quota interaction
// ---------------------------------------------------------------------------

test('F26-U3-QUOTA-01: contact quota exceeded marks inbound event as failed', function (): void {
    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    // Bind a CapacityGuard that throws TenantQuotaExceededException for Contacts
    $quotaGuard = new class implements CapacityGuardInterface
    {
        public function withinLock(Tenant $tenant, UsageCategory $category, Closure $operation): mixed
        {
            if ($category === UsageCategory::Contacts) {
                $this->assertCanCreate($tenant, $category);
            }

            return $operation(new class implements CapacityCheckInterface
            {
                public function assertCanCreate(): void {}
            });
        }

        public function assertCanCreate(Tenant $tenant, UsageCategory $category): void
        {
            if ($category === UsageCategory::Contacts) {
                throw TenantQuotaExceededException::forQuota($category->value, 100, 100);
            }
        }
    };

    app()->instance(CapacityGuardInterface::class, $quotaGuard);

    // Dispatch through the real webhook — the inbound job will call
    // MessageService → ContactService → CapacityGuard → exception
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $messageId = 'wamid-quota-'.uniqid('', true);

    post_whatsapp_webhook(whatsapp_webhook_payload($messageId, 'phone-1'))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', $messageId)->firstOrFail();

    expect($event->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->error_code)->toBe('contact_quota_exceeded');
});
