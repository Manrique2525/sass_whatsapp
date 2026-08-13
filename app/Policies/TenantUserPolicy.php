<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;

/**
 * Policy de miembros del tenant. Capa programática: delega en
 * `AuthorizationService` (la autorización efectiva vive en los services de
 * aplicación, ver ADR-026).
 */
final class TenantUserPolicy
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->authorization->can($user, TenantPermission::ViewUsers, $tenant);
    }

    public function update(User $user, TenantUser $membership, Tenant $tenant): bool
    {
        return $this->authorization->can($user, TenantPermission::UpdateUsers, $tenant);
    }

    public function delete(User $user, TenantUser $membership, Tenant $tenant): bool
    {
        return $this->authorization->can($user, TenantPermission::RemoveUsers, $tenant);
    }
}
