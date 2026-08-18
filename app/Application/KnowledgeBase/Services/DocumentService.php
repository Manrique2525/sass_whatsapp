<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\KnowledgeBase\Exceptions\DocumentNotFoundException;
use App\Domain\KnowledgeBase\Exceptions\KnowledgeBaseNotFoundException;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Casos de uso de administración de documentos de Knowledge Base (FASE 17 U2.1).
 *
 * U2.1 implementa únicamente las operaciones que NO requieren Storage:
 * index, show y delete (metadata únicamente). El upload real (POST multipart)
 * está diferido a U2.2.
 *
 * Invariantes:
 * - El documento se resuelve filtrando por `tenant_id` autorizado.
 * - Cross-tenant → 404 (ADR-010/023).
 * - `tenant_id` nunca viene del frontend.
 * - DocumentNotFoundException se mapea a 404 por el controller.
 */
final class DocumentService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, KnowledgeDocument>
     */
    public function index(User $user, Tenant $tenant, string $knowledgeBaseId, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewKnowledge, $tenant);

        $knowledgeBase = $this->findKnowledgeBaseForTenant($tenant, $knowledgeBaseId);

        $query = KnowledgeDocument::query()
            ->where('knowledge_base_id', $knowledgeBase->id);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where('original_filename', 'like', $term);
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    public function showForUser(User $user, Tenant $tenant, string $knowledgeBaseId, string $documentId): KnowledgeDocument
    {
        $this->authorization->authorize($user, TenantPermission::ViewKnowledge, $tenant);

        $this->findKnowledgeBaseForTenant($tenant, $knowledgeBaseId);

        return $this->findDocumentForTenant($tenant, $documentId);
    }

    public function delete(User $user, Tenant $tenant, string $knowledgeBaseId, string $documentId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageKnowledge, $tenant);

        $this->findKnowledgeBaseForTenant($tenant, $knowledgeBaseId);

        $document = $this->findDocumentForTenant($tenant, $documentId);

        $document->delete();

        $this->auditLogger->record(
            action: 'knowledge_document.deleted',
            data: ['tenant_id' => $tenant->id, 'knowledge_base_id' => $knowledgeBaseId],
            subjectType: KnowledgeDocument::class,
            subjectId: $document->id,
        );
    }

    private function findKnowledgeBaseForTenant(Tenant $tenant, string $knowledgeBaseId): KnowledgeBase
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

    private function findDocumentForTenant(Tenant $tenant, string $documentId): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($documentId)
            ->first();

        if ($document === null) {
            throw new DocumentNotFoundException;
        }

        return $document;
    }
}
