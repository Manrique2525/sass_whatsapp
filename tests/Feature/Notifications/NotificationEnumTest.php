<?php

declare(strict_types=1);

use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;

/*
|--------------------------------------------------------------------------
| Notification Enum Tests (FASE 22 U1)
|--------------------------------------------------------------------------
|
| NOTIF-ENUM-01..04 — Enum contracts.
| Corren en SQLite :memory:.
|
*/

it('NOTIF-ENUM-01: NotificationType has exact expected cases', function (): void {
    $cases = NotificationType::cases();

    $this->assertCount(4, $cases);
    $this->assertEqualsCanonicalizing(
        ['handoff_requested', 'conversation_assigned', 'conversation_claimed', 'system'],
        array_map(fn (NotificationType $c) => $c->value, $cases),
    );
})->group('NOTIF-ENUM-01');

it('NOTIF-ENUM-02: NotificationPriority has exact expected cases', function (): void {
    $cases = NotificationPriority::cases();

    $this->assertCount(3, $cases);
    $this->assertEqualsCanonicalizing(
        ['low', 'normal', 'high'],
        array_map(fn (NotificationPriority $c) => $c->value, $cases),
    );
})->group('NOTIF-ENUM-02');

it('NOTIF-ENUM-03: NotificationType values are stable strings', function (): void {
    $this->assertEquals('handoff_requested', NotificationType::HandoffRequested->value);
    $this->assertEquals('conversation_assigned', NotificationType::ConversationAssigned->value);
    $this->assertEquals('conversation_claimed', NotificationType::ConversationClaimed->value);
    $this->assertEquals('system', NotificationType::System->value);
})->group('NOTIF-ENUM-03');

it('NOTIF-ENUM-04: NotificationType and Priority have labels', function (): void {
    $this->assertNotEmpty(NotificationType::HandoffRequested->label());
    $this->assertNotEmpty(NotificationPriority::Normal->label());
    $this->assertIsString(NotificationType::System->label());
    $this->assertIsString(NotificationPriority::High->label());
})->group('NOTIF-ENUM-04');
