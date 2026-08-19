<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Application\KnowledgeBase\Services\KnowledgeDocumentProcessingService;
use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Procesamiento asíncrono de un documento knowledge (FASE 17 U2.4).
 *
 * Orquesta: extract → normalize → chunk → persist → ready.
 * NO crea embeddings (U3 lo hará).
 *
 * Capas de protección contra duplicación:
 * 1. ShouldBeUnique por tenant+document (cola).
 * 2. Cache::lock por tenant+document (runtime).
 * 3. CAS uploaded→processing (DB).
 * 4. CAS processing→ready/failed (DB).
 */
final class ProcessKnowledgeDocument implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $timeout = 120;

    public function __construct(
        string $tenantId,
        public readonly string $documentId,
    ) {
        $this->tenantId = $tenantId;
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "knowledge-document:{$this->tenantId}:{$this->documentId}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function tries(): int
    {
        return (int) config('knowledge.processing.tries', 3);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('knowledge.processing.backoff', [30, 60]);
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $lock = Cache::lock(
            "lock:tenant:{$this->tenantId}:knowledge-document:{$this->documentId}:processing",
            $this->timeout + 30,
        );

        try {
            $lock->block(seconds: 10);
        } catch (LockTimeoutException) {
            $this->release(5);

            return;
        }

        try {
            $this->processLocked();
        } finally {
            $lock->release();
        }
    }

    private function processLocked(): void
    {
        /** @var KnowledgeDocumentProcessingService $service */
        $service = app(KnowledgeDocumentProcessingService::class);

        $document = $service->beginProcessing($this->tenantId, $this->documentId);

        if ($document === null) {
            return;
        }

        $service->processDocument($document);
    }

    public function failed(?Throwable $exception): void
    {
        DB::connection()->transaction(function (): void {
            $document = KnowledgeDocument::query()
                ->withoutTenantScope()
                ->where('tenant_id', $this->tenantId)
                ->whereKey($this->documentId)
                ->first();

            if ($document === null || $document->deleted_at !== null) {
                return;
            }

            if ($document->status !== KnowledgeDocumentStatus::Processing) {
                return;
            }

            KnowledgeDocument::query()
                ->withoutTenantScope()
                ->where('id', $document->id)
                ->where('tenant_id', $this->tenantId)
                ->where('status', KnowledgeDocumentStatus::Processing)
                ->update([
                    'status' => KnowledgeDocumentStatus::Failed,
                    'error_message' => 'Document processing failed.',
                    'updated_at' => now(),
                ]);

            app(AuditLogger::class)->record(
                action: 'knowledge_document.failed',
                data: [
                    'document_id' => $document->id,
                    'knowledge_base_id' => $document->knowledge_base_id,
                    'error_code' => 'queue_exhausted',
                ],
                subjectType: KnowledgeDocument::class,
                subjectId: $document->id,
                tenantId: $this->tenantId,
            );
        });
    }
}
