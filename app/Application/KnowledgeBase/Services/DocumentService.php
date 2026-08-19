<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Exceptions\DocumentDuplicateException;
use App\Domain\KnowledgeBase\Exceptions\DocumentNotFoundException;
use App\Domain\KnowledgeBase\Exceptions\DocumentStorageFailedException;
use App\Domain\KnowledgeBase\Exceptions\KnowledgeBaseNotFoundException;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;

/**
 * Casos de uso de administración de documentos de Knowledge Base (FASE 17 U2.1+U2.2).
 *
 * U2.2 agrega el upload real: validación → hash → dedup → storage write →
 * DB row → compensación si DB falla → audit.
 *
 * Invariantes:
 * - Un KnowledgeDocument solo puede existir si el source file fue persistido.
 * - tenant_id viene de TenantContext, nunca del frontend.
 * - storage_path es 100% server-side.
 * - Si DB falla después de storage write, se limpia el objeto exacto.
 */
final class DocumentService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly DocumentUploadValidator $validator,
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

    public function upload(User $user, Tenant $tenant, string $knowledgeBaseId, UploadedFile $file): KnowledgeDocument
    {
        $this->authorization->authorize($user, TenantPermission::ManageKnowledge, $tenant);

        $knowledgeBase = $this->findKnowledgeBaseForTenant($tenant, $knowledgeBaseId);

        $config = config('knowledge.upload');

        $this->validator->validate($file, $config);

        $fileHash = $this->calculateHash($file);

        $this->assertNotDuplicate($tenant->id, $knowledgeBase->id, $fileHash);

        $documentId = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension());
        $storagePath = $this->buildStoragePath($tenant->id, $knowledgeBase->id, $documentId, $extension);
        $disk = $config['storage_disk'];

        try {
            $path = $file->storeAs(
                dirname($storagePath),
                basename($storagePath),
                ['disk' => $disk],
            );

            if ($path === false) {
                throw new DocumentStorageFailedException('Storage retornó falso.');
            }

            $document = $this->createDocumentRow(
                tenantId: $tenant->id,
                knowledgeBaseId: $knowledgeBase->id,
                documentId: $documentId,
                originalFilename: $file->getClientOriginalName(),
                storageDisk: $disk,
                storagePath: $storagePath,
                mimeType: $this->detectServerMime($file),
                fileSize: $file->getSize(),
                fileHash: $fileHash,
            );
        } catch (PDOException|QueryException) {
            Storage::disk($disk)->delete($storagePath);

            throw new DocumentStorageFailedException('Error al persistir el registro.');
        } catch (DocumentStorageFailedException $e) {
            throw $e;
        } catch (\Exception) {
            Storage::disk($disk)->delete($storagePath);

            throw new DocumentStorageFailedException;
        }

        $this->auditLogger->record(
            action: 'knowledge_document.uploaded',
            data: [
                'document_id' => $document->id,
                'knowledge_base_id' => $knowledgeBase->id,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size,
                'status' => $document->status->value,
            ],
            subjectType: KnowledgeDocument::class,
            subjectId: $document->id,
        );

        return $document;
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

    private function calculateHash(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new DocumentStorageFailedException('No se puede acceder al archivo para calcular hash.');
        }

        $hash = hash_file('sha256', $realPath);

        if ($hash === false) {
            throw new DocumentStorageFailedException('No se pudo calcular el hash del archivo.');
        }

        return $hash;
    }

    private function assertNotDuplicate(string $tenantId, string $knowledgeBaseId, string $fileHash): void
    {
        $exists = KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where('file_hash', $fileHash)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw new DocumentDuplicateException;
        }
    }

    private function buildStoragePath(string $tenantId, string $knowledgeBaseId, string $documentId, string $extension): string
    {
        $prefix = config('knowledge.upload.storage_prefix', 'knowledge');

        return "{$prefix}/tenant/{$tenantId}/knowledge-bases/{$knowledgeBaseId}/documents/{$documentId}/source.{$extension}";
    }

    private function detectServerMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->getRealPath());

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    private function createDocumentRow(
        string $tenantId,
        string $knowledgeBaseId,
        string $documentId,
        string $originalFilename,
        string $storageDisk,
        string $storagePath,
        string $mimeType,
        int $fileSize,
        string $fileHash,
    ): KnowledgeDocument {
        return DB::transaction(function () use (
            $tenantId,
            $knowledgeBaseId,
            $documentId,
            $originalFilename,
            $storageDisk,
            $storagePath,
            $mimeType,
            $fileSize,
            $fileHash,
        ): KnowledgeDocument {
            $document = new KnowledgeDocument;
            $document->id = $documentId;
            $document->tenant_id = $tenantId;
            $document->knowledge_base_id = $knowledgeBaseId;
            $document->original_filename = $originalFilename;
            $document->storage_disk = $storageDisk;
            $document->storage_path = $storagePath;
            $document->mime_type = $mimeType;
            $document->file_size = $fileSize;
            $document->file_hash = $fileHash;
            $document->status = KnowledgeDocumentStatus::Uploaded;
            $document->chunk_count = 0;
            $document->save();

            return $document;
        });
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
