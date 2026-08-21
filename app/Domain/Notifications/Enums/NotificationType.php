<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/**
 * Notification types (FASE 22 U1, ADR-082).
 *
 * Closed enum — only types with existing producers are included.
 * Additional types added when new event producers land.
 *
 * Privacy: type identifies the event category only. No PII in type values.
 */
enum NotificationType: string
{
    case HandoffRequested = 'handoff_requested';
    case ConversationAssigned = 'conversation_assigned';
    case ConversationClaimed = 'conversation_claimed';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::HandoffRequested => 'Handoff requested',
            self::ConversationAssigned => 'Conversation assigned',
            self::ConversationClaimed => 'Conversation claimed',
            self::System => 'System',
        };
    }
}
