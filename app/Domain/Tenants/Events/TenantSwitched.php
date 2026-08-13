<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Events;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se cambió el tenant activo de un usuario. Los listeners pueden enriquecer la
 * sesión (caché, notificaciones, limpieza de datos del tenant anterior).
 */
final class TenantSwitched
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly Tenant $tenant,
    ) {}
}
