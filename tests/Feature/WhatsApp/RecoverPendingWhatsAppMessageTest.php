<?php

declare(strict_types=1);

use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\RecoverPendingWhatsAppMessage;
use App\Jobs\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| RecoverPendingWhatsAppMessage Tests (FASE 29 U2)
|--------------------------------------------------------------------------
|
| F29-U2-REC-01..07 — handle() delegation, idempotency, missing message,
| tenant safety, provider failure.
| Note: RecoverPendingWhatsAppMessage instantiates SendWhatsAppMessage directly
| (new SendWhatsAppMessage()->handle()), so Queue::fake cannot intercept.
| Tests verify state transitions and TenantContext behavior.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->contact = make_contact($this->tenant);
    $this->conversation = make_conversation($this->tenant, $this->contact);
    TenantContext::setId($this->tenant->id);
});

it('F29-U2-REC-01: job constructs with correct tenant_id, conversation_id, message_id', function (): void {
    $job = new RecoverPendingWhatsAppMessage(
        $this->tenant->id,
        $this->conversation->id,
        'msg-123',
    );

    expect($job->tenantId)->toBe($this->tenant->id)
        ->and($job->conversationId)->toBe($this->conversation->id)
        ->and($job->messageId)->toBe('msg-123');
})->group('F29-U2-REC');

it('F29-U2-REC-02: tries() returns more than whatsapp.max_attempts', function (): void {
    $job = new RecoverPendingWhatsAppMessage(
        $this->tenant->id,
        $this->conversation->id,
        'msg-123',
    );

    expect($job->tries())->toBeGreaterThan((int) config('whatsapp.max_attempts', 3));
})->group('F29-U2-REC');

it('F29-U2-REC-03: handle() with pending message transitions to sending then fails gracefully', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test recovery',
        'status' => MessageStatus::Pending->value,
        'metadata' => [],
    ]);

    $job = new RecoverPendingWhatsAppMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id,
    );
    $job->handle();

    $message->refresh();
    expect(in_array($message->status->value, ['sending', 'failed'], true))->toBeTrue();
})->group('F29-U2-REC');

it('F29-U2-REC-04: handle with missing message does not throw', function (): void {
    $job = new RecoverPendingWhatsAppMessage(
        $this->tenant->id,
        $this->conversation->id,
        '00000000-0000-0000-0000-000000000000',
    );

    $job->handle();

    expect(true)->toBeTrue();
})->group('F29-U2-REC');

it('F29-U2-REC-05: handle sets and restores TenantContext correctly', function (): void {
    $previousTenant = Tenant::factory()->create();
    TenantContext::setId($previousTenant->id);

    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => MessageStatus::Pending->value,
        'metadata' => [],
    ]);

    $job = new RecoverPendingWhatsAppMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id,
    );
    $job->handle();

    expect(TenantContext::id())->toBe($previousTenant->id);
})->group('F29-U2-REC');

it('F29-U2-REC-06: failed() marks message as failed', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => MessageStatus::Sending->value,
        'metadata' => [],
    ]);

    $job = new RecoverPendingWhatsAppMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id,
    );

    $job->failed(new RuntimeException('worker crashed'));

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed);
})->group('F29-U2-REC');

it('F29-U2-REC-07: failed() with null exception does not throw', function (): void {
    $message = Message::withoutTenantScope()->create([
        'tenant_id' => $this->tenant->id,
        'conversation_id' => $this->conversation->id,
        'direction' => 'outbound',
        'type' => 'text',
        'body' => 'test',
        'status' => MessageStatus::Sending->value,
        'metadata' => [],
    ]);

    $job = new RecoverPendingWhatsAppMessage(
        $this->tenant->id,
        $this->conversation->id,
        $message->id,
    );

    $job->failed(null);

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed);
})->group('F29-U2-REC');
