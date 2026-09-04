<?php

declare(strict_types=1);

namespace App\Application\Tenants\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\PlanNotFoundException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Caso de uso: provisionar el primer workspace de un usuario recién registrado
 * (FASE 33 U1, ADR-124).
 *
 * Crea, en una única transacción, el tenant/workspace del usuario, su membresía
 * como `owner` (activa), fija `current_tenant_id` y le concede el plan `free`
 * mediante una suscripción activa. El slug se autogenera y es collision-safe
 * (la constraint UNIQUE de la BD es la autoridad final ante carreras).
 *
 * El usuario NO elige slug ni nombre de workspace en este U1: el nombre se
 * deriva de su nombre y el slug de ese nombre. Nunca se confía en datos del
 * frontend para la creación del workspace.
 */
final class ProvisionNewWorkspace
{
    public function __construct(
        private readonly TenantRoleManager $roleManager,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Provisiona el workspace del usuario. Debe ejecutarse DENTRO de una
     * transacción orquestada por el controller para que el alta del usuario y
     * el alta del workspace sean atómicas.
     */
    public function provision(User $user): Tenant
    {
        $freePlan = Plan::query()->where('slug', 'free')->where('is_active', true)->first();

        if ($freePlan === null) {
            throw new PlanNotFoundException('El plan free no está disponible en el catálogo.');
        }

        $tenant = $this->createTenantWithSlug($user, $freePlan);

        $this->createOwnerSubscription($tenant, $freePlan);
        $this->makeOwner($user, $tenant);
        $this->setCurrentTenant($user, $tenant);

        $this->auditLogger->record(
            action: 'tenant.created',
            data: ['tenant_id' => $tenant->id, 'tenant_slug' => $tenant->slug],
            subjectType: Tenant::class,
            subjectId: $tenant->id,
            tenantId: $tenant->id,
        );

        return $tenant->fresh();
    }

    private function createTenantWithSlug(User $user, Plan $freePlan): Tenant
    {
        // Workspace name derivado del usuario: "Mi espacio" + nombre. Se omite
        // para no presumir género/idioma; lo importante es que derive del nombre
        // y sea estable entre runs.
        $workspaceName = trim($user->name);

        $baseSlug = Str::slug($workspaceName);
        $slug = $baseSlug !== '' ? $baseSlug : 'workspace';

        // Collision-safe: añade un sufijo corto aleatorio mientras exista.
        // La UNIQUE(tenants.slug) de la BD sigue siendo la autoridad final.
        $attempts = 0;
        while ($attempts < 10 && $this->slugExists($slug)) {
            $slug = sprintf('%s-%s', $baseSlug !== '' ? $baseSlug : 'workspace', Str::random(4));
            $attempts++;
        }

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->create([
            'name' => $workspaceName,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan_id' => $freePlan->id,
            'timezone' => 'UTC',
            'locale' => 'es',
        ]);

        return $tenant;
    }

    private function slugExists(string $slug): bool
    {
        return Tenant::query()->where('slug', $slug)->exists();
    }

    private function createOwnerSubscription(Tenant $tenant, Plan $freePlan): void
    {
        // Subscription es BelongsToTenant: exige TenantContext para crear.
        $previousTenantId = TenantContext::id();
        TenantContext::setId($tenant->id);

        try {
            Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $freePlan->id,
                'status' => SubscriptionStatus::Active,
                'quantity' => 1,
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->addMonth()->startOfMonth(),
            ]);
        } finally {
            if ($previousTenantId !== null) {
                TenantContext::setId($previousTenantId);
            } else {
                TenantContext::clear();
            }
        }
    }

    private function makeOwner(User $user, Tenant $tenant): void
    {
        $user->tenants()->syncWithoutDetaching([$tenant->id => [
            'role' => UserRole::Owner->value,
            'status' => TenantMembershipStatus::Active->value,
            'joined_at' => now(),
        ]]);

        // Materializa el rol owner en spatie modo teams (espejo de la fuente de
        // verdad `tenant_users.role`), scopeado al tenant id (ADR-025/026).
        $this->roleManager->syncRoles($user, $tenant, UserRole::Owner);
    }

    private function setCurrentTenant(User $user, Tenant $tenant): void
    {
        $user->forceFill(['current_tenant_id' => $tenant->id])->save();
    }
}
