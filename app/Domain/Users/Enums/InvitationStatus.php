<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

/**
 * Estado de una invitación a un tenant (`tenant_invitations.status`).
 *
 * Transiciones: pending → accepted | revoked | expired. Una vez salida de
 * `pending`, la invitación no se puede reutilizar (ADR-027).
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public static function fromString(string $status): self
    {
        return self::from($status);
    }
}
