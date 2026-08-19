<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Audit\Services\AuditLogger;
use App\Application\KnowledgeBase\Services\EmbeddingMaterializationService;
use App\Domain\AI\Exceptions\EmbeddingAuthFailedException;
use App\Domain\AI\Exceptions\EmbeddingDimensionMismatchException;
use App\Domain\AI\Exceptions\EmbeddingProviderException;
use App\Domain\AI\Exceptions\EmbeddingRateLimitException;
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
 * Materialización asíncrona de embeddings vectoriales (FASE 17 U3.2).
 *
 * Solo procesa chunks con embedding IS NULL de documentos en estado Ready.
 * Idempotencia: re-ejecución solo procesa chunks pendientes.
 *
 * Capas de protección contra duplicación:
 * 1. ShouldBeUnique por tenant+document (cola).
 * 2. Cache::lock por tenant+document (runtime).
 * 3. CAS WHERE embedding IS NULL (DB).
 */
final class MaterializeKnowledgeEmbeddings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $timeout = 180;

    public function __construct(
        string $tenantId,
        public readonly string $documentId,
    ) {
        $this->tenantId = $tenantId;
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "embeddings:{$this->tenantId}:{$this->documentId}";
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function tries(): int
    {
        return (int) config('knowledge.materialization.tries', 3);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('knowledge.materialization.backoff', [30, 60, 120]);
    }

    protected function executeInTenantContext(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $lock = Cache::lock(
            "lock:tenant:{$this->tenantId}:embeddings:{$this->documentId}:processing",
            $this->timeout + 30,
        );

        try {
            $lock->block(seconds: 10);
        } catch (LockTimeoutException) {
            $this->release(5);

            return;
        }

        try {
            $this->materializeLocked();
        } finally {
            $lock->release();
        }
    }

    private function materializeLocked(): void
    {
        $document = KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->documentId)
            ->first();

        if ($document === null || $document->deleted_at !== null) {
            return;
        }

        if ($document->status !== KnowledgeDocumentStatus::Ready) {
            return;
        }

        /** @var EmbeddingMaterializationService $service */
        $service = app(EmbeddingMaterializationService::class);

        $service->materialize($document);
    }

    public function failed(?Throwable $exception): void
    {
        DB::connection()->transaction(function () use ($exception): void {
            $document = KnowledgeDocument::query()
                ->withoutTenantScope()
                ->where('tenant_id', $this->tenantId)
                ->whereKey($this->documentId)
                ->first();

            if ($document === null || $document->deleted_at !== null) {
                return;
            }

            if ($document->status !== KnowledgeDocumentStatus::Ready) {
                return;
            }

            $errorCode = $this->classifyError($exception);

            app(AuditLogger::class)->record(
                action: 'knowledge_embeddings.failed',
                data: [
                    'document_id' => $document->id,
                    'knowledge_base_id' => $document->knowledge_base_id,
                    'error_code' => $errorCode,
                ],
                subjectType: KnowledgeDocument::class,
                subjectId: $document->id,
                tenantId: $this->tenantId,
            );
        });
    }

    private function classifyError(?Throwable $exception): string
    {
        if ($exception === null) {
            return 'unknown';
        }

        return match (true) {
            $exception instanceof EmbeddingAuthFailedException => 'auth_failed',
            $exception instanceof EmbeddingRateLimitException => 'rate_limited',
            $exception instanceof EmbeddingDimensionMismatchException => 'dimension_mismatch',
            $exception instanceof EmbeddingProviderException => 'provider_error',
            default => 'queue_exhausted',
        };
    }
}
