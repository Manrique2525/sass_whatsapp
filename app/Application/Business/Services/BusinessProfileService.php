<?php

declare(strict_types=1);

namespace App\Application\Business\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;

/**
 * Casos de uso del perfil de negocio (FASE 5, ADR-028).
 *
 * Invariante 1:1: cada tenant tiene exactamente un perfil; si no existe, se
 * crea bajo demanda en la primera lectura/escritura. Nunca se acepta
 * `tenant_id` desde el frontend: la pertenencia la decide TenantContext +
 * `BelongsToTenant`, y la autorización (membresía + rol + permiso) la resuelve
 * `AuthorizationService`.
 */
final class BusinessProfileService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function showForUser(User $user, Tenant $tenant): BusinessProfile
    {
        $this->authorization->authorize($user, TenantPermission::ViewBusinessProfile, $tenant);

        return $this->getOrCreateFor($tenant);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, Tenant $tenant, array $validated): BusinessProfile
    {
        $this->authorization->authorize($user, TenantPermission::UpdateBusinessProfile, $tenant);

        $profile = $this->getOrCreateFor($tenant);

        $changed = array_intersect_key(
            $validated,
            array_flip($profile->getFillable()),
        );

        if ($changed === []) {
            return $profile;
        }

        $profile->fill($changed)->save();

        $this->auditLogger->record(
            action: 'business_profile.updated',
            data: [
                'tenant_id' => $tenant->id,
                'changed' => array_keys($changed),
            ],
            subjectType: BusinessProfile::class,
            subjectId: $profile->id,
        );

        return $profile->fresh();
    }

    /**
     * Devuelve el perfil del tenant o lo crea (invariante 1:1).
     */
    private function getOrCreateFor(Tenant $tenant): BusinessProfile
    {
        $profile = $tenant->businessProfile;

        if ($profile !== null) {
            return $profile;
        }

        $profile = $tenant->businessProfile()->create();

        $this->auditLogger->record(
            action: 'business_profile.created',
            data: ['tenant_id' => $tenant->id],
            subjectType: BusinessProfile::class,
            subjectId: $profile->id,
        );

        return $profile;
    }
}
