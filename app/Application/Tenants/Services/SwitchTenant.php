<?php

declare(strict_types=1);

namespace App\Application\Tenants\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Events\TenantSwitched;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;

/**
 * Cambia el tenant activo de un usuario.
 *
 * Reglas de seguridad:
 * - Solo se permite si el usuario es miembro del tenant (`tenant_users`).
 * - Nunca se confía en `current_tenant_id` previo ni en `tenant_id` del request.
 * - El tenant debe estar activo (un tenant suspendido no es usable).
 */
final class SwitchTenant
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $user, Tenant $tenant): void
    {
        if (! $user->belongsToTenant($tenant)) {
            throw new TenantMembershipException('El usuario no pertenece al tenant.');
        }

        if ($tenant->status !== TenantStatus::Active) {
            throw new TenantNotActiveException('El tenant no está activo.');
        }

        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        TenantContext::set($tenant);

        $this->auditLogger->record(
            action: 'tenant.switched',
            data: ['tenant_id' => $tenant->id, 'tenant_slug' => $tenant->slug],
            subjectType: Tenant::class,
            subjectId: $tenant->id,
        );

        TenantSwitched::dispatch($user, $tenant);
    }
}
