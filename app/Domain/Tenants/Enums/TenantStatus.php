<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Enums;

/**
 * Estados de vida de un tenant.
 *
 * Un tenant `suspended` no puede usarse como tenant activo: el middleware
 * `tenant`, el switch y los servicios de aplicación lo rechazan.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public static function fromString(string $status): self
    {
        return self::from($status);
    }
}
