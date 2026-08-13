<?php

declare(strict_types=1);

namespace App\Domain\Users\Enums;

/**
 * Estado de la membresía de un usuario en un tenant (`tenant_users.status`).
 */
enum TenantMembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Disabled = 'disabled';

    public static function fromString(string $status): self
    {
        return self::from($status);
    }
}
