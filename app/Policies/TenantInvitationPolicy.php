<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantInvitation;
use App\Domain\Users\Models\User;

/**
 * Policy de invitaciones a tenant. Capa programática: delega en
 * `AuthorizationService` (ver ADR-026).
 */
final class TenantInvitationPolicy
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->authorization->can($user, TenantPermission::InviteUsers, $tenant);
    }

    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->authorization->can($user, TenantPermission::ViewUsers, $tenant);
    }

    public function update(User $user, TenantInvitation $invitation, Tenant $tenant): bool
    {
        return $this->authorization->can($user, TenantPermission::InviteUsers, $tenant);
    }

    public function delete(User $user, TenantInvitation $invitation, Tenant $tenant): bool
    {
        return $this->authorization->can($user, TenantPermission::InviteUsers, $tenant);
    }
}
