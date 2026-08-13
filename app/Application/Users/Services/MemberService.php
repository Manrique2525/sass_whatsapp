<?php

declare(strict_types=1);

namespace App\Application\Users\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Exceptions\RoleChangeNotAllowedException;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gestión de miembros de un tenant (listar, cambiar rol, remover).
 *
 * Toda operación parte de un actor autenticado y pasa por
 * `AuthorizationService` (membresía + permiso). Las reglas de rol:
 *
 * - owner: cambia roles de miembros no-owner (admin/agent); puede cambiar el rol
 *   de otro owner solo si quedan más de un owner; nunca toca al último owner.
 * - admin: NO tiene `roles.assign` (403) — la asignación de roles es exclusiva
 *   de owner. (Defensa extra: admin solo podría operar sobre agentes.)
 * - Nadie remueve al último owner.
 */
final class MemberService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantRoleManager $roleManager,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return Collection<int, TenantUser>
     */
    public function list(User $actor, Tenant $tenant): Collection
    {
        $this->authorization->authorize($actor, TenantPermission::ViewUsers, $tenant);

        return TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantMembershipStatus::Active)
            ->with('user')
            ->orderBy('joined_at')
            ->get();
    }

    public function changeRole(User $actor, Tenant $tenant, User $target, UserRole $newRole): TenantUser
    {
        $this->authorization->authorize($actor, TenantPermission::UpdateUsers, $tenant);
        $this->authorization->authorize($actor, TenantPermission::AssignRoles, $tenant);

        $membership = $this->membershipOrFail($tenant, $target);
        $actorRole = $actor->roleForTenant($tenant->id);
        $ownerCount = $this->activeOwnerCount($tenant);

        if ($actorRole === UserRole::Owner) {
            if ($membership->role === UserRole::Owner && $ownerCount <= 1) {
                throw new RoleChangeNotAllowedException('No se puede cambiar el rol del último owner.');
            }

            if (! in_array($newRole, UserRole::assignableTenantRoles(), true)) {
                throw new RoleChangeNotAllowedException('El rol de destino no es asignable.');
            }
        } else {
            // Defensa extra: solo owner tiene `roles.assign`; de llegar un actor
            // con asignación de roles pero sin reglas de owner, restringir a agents.
            if ($membership->role !== UserRole::Agent || $newRole !== UserRole::Agent) {
                throw new RoleChangeNotAllowedException('Tu rol no permite cambiar este miembro.');
            }
        }

        if ($membership->role === $newRole) {
            return $membership;
        }

        $membership->update(['role' => $newRole]);
        $this->roleManager->syncRoles($target, $tenant, $newRole);

        $this->auditLogger->record(
            action: 'user.role_changed',
            data: [
                'tenant_id' => $tenant->id,
                'target_user_id' => $target->id,
                'from' => $membership->role->value,
                'to' => $newRole->value,
            ],
            subjectType: TenantUser::class,
            subjectId: $membership->id,
            actorUserId: $actor->id,
            tenantId: $tenant->id,
        );

        return $membership->fresh();
    }

    public function remove(User $actor, Tenant $tenant, User $target): void
    {
        $this->authorization->authorize($actor, TenantPermission::RemoveUsers, $tenant);

        $membership = $this->membershipOrFail($tenant, $target);
        $actorRole = $actor->roleForTenant($tenant->id);
        $ownerCount = $this->activeOwnerCount($tenant);

        if ($membership->role === UserRole::Owner) {
            if ($actorRole !== UserRole::Owner || $ownerCount <= 1) {
                throw new RoleChangeNotAllowedException('No se puede remover al último owner.');
            }
        } elseif ($actorRole !== UserRole::Owner) {
            // admin: solo agents.
            if ($membership->role !== UserRole::Agent) {
                throw new RoleChangeNotAllowedException('Solo puedes remover agentes.');
            }
        }

        $this->roleManager->revokeRoles($target, $tenant);

        TenantUser::query()->whereKey($membership->id)->delete();

        if ($target->current_tenant_id === $tenant->id) {
            $target->forceFill(['current_tenant_id' => null])->save();
        }

        $this->auditLogger->record(
            action: 'user.removed',
            data: [
                'tenant_id' => $tenant->id,
                'target_user_id' => $target->id,
                'role' => $membership->role->value,
            ],
            subjectType: TenantUser::class,
            subjectId: $membership->id,
            actorUserId: $actor->id,
            tenantId: $tenant->id,
        );
    }

    private function membershipOrFail(Tenant $tenant, User $target): TenantUser
    {
        $membership = TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $target->id)
            ->where('status', TenantMembershipStatus::Active)
            ->first();

        if ($membership === null) {
            throw new TenantMembershipException('El usuario no es miembro activo del tenant.');
        }

        return $membership;
    }

    private function activeOwnerCount(Tenant $tenant): int
    {
        return TenantUser::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', UserRole::Owner->value)
            ->where('status', TenantMembershipStatus::Active)
            ->count();
    }
}
