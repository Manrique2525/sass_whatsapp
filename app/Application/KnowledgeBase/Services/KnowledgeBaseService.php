<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\KnowledgeBase\Exceptions\KnowledgeBaseDuplicateException;
use App\Domain\KnowledgeBase\Exceptions\KnowledgeBaseNotFoundException;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Casos de uso de administración de Knowledge Bases (FASE 17 U2.1).
 *
 * Invariantes:
 * - `name` se trimmea y normaliza a UTF-8 NFC.
 * - El nombre es único por tenant (`(tenant_id, name) WHERE deleted_at IS NULL`).
 * - La KB se resuelve SIN el scope global (`withoutTenantScope`) pero
 *   filtrando SIEMPRE por `tenant_id` del tenant autorizado: el 404 oculta la
 *   existencia cross-tenant (ADR-010/023).
 * - `tenant_id` nunca viene del frontend: lo fija `BelongsToTenant` con el
 *   TenantContext activo.
 */
final class KnowledgeBaseService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, KnowledgeBase>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewKnowledge, $tenant);

        $query = KnowledgeBase::query();

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    public function showForUser(User $user, Tenant $tenant, string $knowledgeBaseId): KnowledgeBase
    {
        $this->authorization->authorize($user, TenantPermission::ViewKnowledge, $tenant);

        return $this->findForTenant($tenant, $knowledgeBaseId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, Tenant $tenant, array $validated): KnowledgeBase
    {
        $this->authorization->authorize($user, TenantPermission::ManageKnowledge, $tenant);

        $name = trim((string) $validated['name']);
        $this->assertNameUnique($tenant, $name, null);

        try {
            $knowledgeBase = KnowledgeBase::query()->create([
                'name' => $name,
                'description' => $validated['description'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException|QueryException) {
            throw new KnowledgeBaseDuplicateException($name);
        }

        $this->auditLogger->record(
            action: 'knowledge_base.created',
            data: ['tenant_id' => $tenant->id],
            subjectType: KnowledgeBase::class,
            subjectId: $knowledgeBase->id,
        );

        return $knowledgeBase;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, Tenant $tenant, string $knowledgeBaseId, array $validated): KnowledgeBase
    {
        $this->authorization->authorize($user, TenantPermission::ManageKnowledge, $tenant);

        $knowledgeBase = $this->findForTenant($tenant, $knowledgeBaseId);

        $data = $validated;

        if (isset($data['name']) && $data['name'] !== '') {
            $data['name'] = trim((string) $data['name']);
            $this->assertNameUnique($tenant, $data['name'], $knowledgeBase->id);
        }

        $changed = array_intersect_key($data, array_flip($knowledgeBase->getFillable()));

        if ($changed === []) {
            return $knowledgeBase;
        }

        $knowledgeBase->fill($changed)->save();

        $this->auditLogger->record(
            action: 'knowledge_base.updated',
            data: [
                'tenant_id' => $tenant->id,
                'changed' => array_keys($changed),
            ],
            subjectType: KnowledgeBase::class,
            subjectId: $knowledgeBase->id,
        );

        return $knowledgeBase->fresh();
    }

    public function delete(User $user, Tenant $tenant, string $knowledgeBaseId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageKnowledge, $tenant);

        $knowledgeBase = $this->findForTenant($tenant, $knowledgeBaseId);

        $knowledgeBase->delete();

        $this->auditLogger->record(
            action: 'knowledge_base.deleted',
            data: ['tenant_id' => $tenant->id, 'name' => $knowledgeBase->name],
            subjectType: KnowledgeBase::class,
            subjectId: $knowledgeBase->id,
        );
    }

    private function assertNameUnique(Tenant $tenant, string $name, ?string $excludeId): void
    {
        $exists = KnowledgeBase::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->exists();

        if ($exists) {
            throw new KnowledgeBaseDuplicateException($name);
        }
    }

    private function findForTenant(Tenant $tenant, string $knowledgeBaseId): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($knowledgeBaseId)
            ->first();

        if ($knowledgeBase === null) {
            throw new KnowledgeBaseNotFoundException;
        }

        return $knowledgeBase;
    }
}
