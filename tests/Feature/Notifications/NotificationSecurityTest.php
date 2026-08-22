<?php

declare(strict_types=1);

use App\Application\Notifications\Services\NotificationService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Notification Security Tests (FASE 22 U2)
|--------------------------------------------------------------------------
|
| NOTIF-SEC-01..08 — Security and privacy hardening.
| Corren en SQLite :memory:.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->user = User::factory()->create();
    make_tenant_member($this->user, $this->tenant, 'owner');

    $this->contact = make_contact($this->tenant, [
        'phone' => '+521234567890',
        'email' => 'test@example.com',
    ]);

    TenantContext::setId($this->tenant->id);
    $this->conversation = Conversation::query()->create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'status' => ConversationStatus::Open,
    ]);

    $this->service = app(NotificationService::class);
});

it('NOTIF-SEC-01: no PII in notification title', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertStringNotContainsString('+521234567890', $notification->title);
        $this->assertStringNotContainsString('test@example.com', $notification->title);
    }
})->group('NOTIF-SEC-01');

it('NOTIF-SEC-02: no PII in notification body', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertStringNotContainsString('+521234567890', $notification->body);
        $this->assertStringNotContainsString('test@example.com', $notification->body);
    }
})->group('NOTIF-SEC-02');

it('NOTIF-SEC-03: no PII in audit log', function (): void {
    $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    $audits = AuditLog::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('action', 'notification.created')
        ->get();

    foreach ($audits as $audit) {
        $dataString = json_encode($audit->data);
        $this->assertStringNotContainsString('+521234567890', $dataString);
        $this->assertStringNotContainsString('test@example.com', $dataString);
        $this->assertStringNotContainsString($this->contact->name, $dataString);
    }
})->group('NOTIF-SEC-03');

it('NOTIF-SEC-04: no HTML in notification title or body', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertStringNotContainsString('<script', $notification->title);
        $this->assertStringNotContainsString('<script', $notification->body);
        $this->assertStringNotContainsString('<div', $notification->title);
        $this->assertStringNotContainsString('<div', $notification->body);
        $this->assertStringNotContainsString('javascript:', $notification->title);
        $this->assertStringNotContainsString('javascript:', $notification->body);
    }
})->group('NOTIF-SEC-04');

it('NOTIF-SEC-05: no tenant_id injection via data JSON', function (): void {
    $result = $this->service->handleConversationAssigned(
        $this->tenant,
        $this->conversation,
        $this->user->id,
    );

    $this->assertNotNull($result);
    $this->assertEquals($this->tenant->id, $result->tenant_id);
    $this->assertArrayNotHasKey('tenant_id', $result->data);
})->group('NOTIF-SEC-05');

it('NOTIF-SEC-06: no user model serialization in notification data', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $this->assertArrayNotHasKey('user', $notification->data);
        $this->assertArrayNotHasKey('password', $notification->data);
        $this->assertArrayNotHasKey('remember_token', $notification->data);
        $this->assertArrayNotHasKey('email_verified_at', $notification->data);
    }
})->group('NOTIF-SEC-06');

it('NOTIF-SEC-07: no raw SQL interpolation — safe data structure', function (): void {
    $notification = $this->service->handleConversationAssigned(
        $this->tenant,
        $this->conversation,
        $this->user->id,
    );

    $this->assertNotNull($notification);
    $this->assertIsArray($notification->data);
    $this->assertArrayHasKey('conversation_id', $notification->data);
})->group('NOTIF-SEC-07');

it('NOTIF-SEC-08: no API keys, secrets, or tokens in notification', function (): void {
    $notifications = $this->service->handleHandoffRequested($this->tenant, $this->conversation);

    foreach ($notifications as $notification) {
        $allContent = $notification->title.$notification->body.json_encode($notification->data);
        $this->assertStringNotContainsString('sk-', $allContent);
        $this->assertStringNotContainsString('api_key', $allContent);
        $this->assertStringNotContainsString('secret', $allContent);
        $this->assertStringNotContainsString('token', $allContent);
    }
})->group('NOTIF-SEC-08');
