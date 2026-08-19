<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\KnowledgeBase\Extractors\DocumentTextExtractorFactory;
use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;
use App\Domain\KnowledgeBase\Exceptions\DocumentTextTooLargeException;
use App\Domain\KnowledgeBase\Exceptions\DocumentTooManyChunksException;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Orquestación del pipeline de procesamiento de documentos knowledge (FASE 17 U2.4).
 *
 * Responsabilidad:
 * - Transiciones de estado CAS (Compare-And-Swap)
 * - Lectura de source desde storage
 * - Extracción de texto vía factory
 * - Normalización
 * - Chunking
 * - Persistencia atómica de chunks
 * - Actualización de stats del documento
 * - Manejo seguro de errores (sanitización)
 *
 * Invariantes:
 * - La transición de estado es atómica (FOR UPDATE + CAS)
 * - El trabajo pesado (parse, extract) ocurre FUERA de la transacción DB
 * - La persistencia final es una transacción corta
 * - Los chunks viejos se eliminan solo cuando nuevos están listos
 * - embedding siempre NULL (U3 lo materializará)
 * - Errores sanitizados (no storage paths, no stack traces)
 */
final class KnowledgeDocumentProcessingService
{
    /**
     * Error codes internos para error_message sanitizado.
     */
    private const ERROR_MISSING_SOURCE = 'missing_source';

    private const ERROR_STORAGE_READ = 'storage_read_failed';

    private const ERROR_EXTRACTION = 'extraction_failed';

    private const ERROR_TEXT_TOO_LARGE = 'text_too_large';

    private const ERROR_TOO_MANY_CHUNKS = 'too_many_chunks';

    private const ERROR_PERSISTENCE = 'persistence_failed';

    private const ERROR_UNKNOWN = 'processing_failed';

    /** @var array<string, string> */
    private const ERROR_MESSAGES = [
        self::ERROR_MISSING_SOURCE => 'Source file could not be read.',
        self::ERROR_STORAGE_READ => 'Source file could not be read.',
        self::ERROR_EXTRACTION => 'Document extraction failed.',
        self::ERROR_TEXT_TOO_LARGE => 'Document contains too much text.',
        self::ERROR_TOO_MANY_CHUNKS => 'Document contains too many chunks.',
        self::ERROR_PERSISTENCE => 'Document processing failed.',
        self::ERROR_UNKNOWN => 'Document processing failed.',
    ];

    public function __construct(
        private readonly DocumentTextExtractorFactory $extractorFactory,
        private readonly TextNormalizer $normalizer,
        private readonly DocumentChunker $chunker,
        private readonly ChunkPersistenceService $persistence,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Transición uploaded → processing.
     *
     * Retorna el documento o null si no se pudo tomar (ya processing/deleted/ready).
     * La transición es atómica: SELECT FOR UPDATE + CAS update.
     */
    public function beginProcessing(string $tenantId, string $documentId): ?KnowledgeDocument
    {
        return DB::transaction(function () use ($tenantId, $documentId): ?KnowledgeDocument {
            $document = KnowledgeDocument::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->first();

            if ($document === null || $document->deleted_at !== null) {
                return null;
            }

            if ($document->status !== KnowledgeDocumentStatus::Uploaded) {
                return null;
            }

            $updated = KnowledgeDocument::query()
                ->withoutTenantScope()
                ->where('id', $document->id)
                ->where('tenant_id', $tenantId)
                ->where('status', KnowledgeDocumentStatus::Uploaded)
                ->update([
                    'status' => KnowledgeDocumentStatus::Processing,
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                return null;
            }

            return $document->refresh();
        });
    }

    /**
     * Ejecuta el pipeline completo: extract → normalize → chunk → persist → ready.
     *
     * Si falla, marca el documento como failed con error sanitizado.
     */
    public function processDocument(KnowledgeDocument $document): void
    {
        $startTime = microtime(true);

        try {
            $this->validateDocument($document);

            $sourceContent = $this->readSource($document);

            $extractor = $this->extractorFactory->resolve($document->mime_type);

            $extracted = $extractor->extract($sourceContent);

            $normalized = $this->normalizer->normalizeAndValidate(
                $extracted->text,
                (int) config('knowledge.extraction.max_extracted_text_size', 500 * 1024),
            );

            $chunks = $this->chunker->chunk($normalized);

            $chunkCount = $this->persistence->replaceChunks($document, $chunks);

            $totalTokens = array_reduce($chunks, fn (int $sum, $chunk) => $sum + $chunk->tokenCount, 0);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $this->markReady($document, $chunkCount, $totalTokens, $durationMs);
        } catch (Throwable $e) {
            $this->markFailed($document, $e);
        }
    }

    /**
     * Marca documento como failed con error sanitizado.
     */
    private function markFailed(KnowledgeDocument $document, Throwable $previous): void
    {
        $errorCode = $this->classifyError($previous);
        $safeMessage = self::ERROR_MESSAGES[$errorCode] ?? self::ERROR_MESSAGES[self::ERROR_UNKNOWN];

        KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('id', $document->id)
            ->where('tenant_id', $document->tenant_id)
            ->where('status', KnowledgeDocumentStatus::Processing)
            ->update([
                'status' => KnowledgeDocumentStatus::Failed,
                'error_message' => $safeMessage,
                'updated_at' => now(),
            ]);

        $this->auditLogger->record(
            action: 'knowledge_document.failed',
            data: [
                'document_id' => $document->id,
                'knowledge_base_id' => $document->knowledge_base_id,
                'error_code' => $errorCode,
            ],
            subjectType: KnowledgeDocument::class,
            subjectId: $document->id,
            tenantId: $document->tenant_id,
        );
    }

    /**
     * Marca documento como ready (ingestion/chunking complete).
     */
    private function markReady(KnowledgeDocument $document, int $chunkCount, int $totalTokens, int $durationMs): void
    {
        KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('id', $document->id)
            ->where('tenant_id', $document->tenant_id)
            ->where('status', KnowledgeDocumentStatus::Processing)
            ->update([
                'status' => KnowledgeDocumentStatus::Ready,
                'chunk_count' => $chunkCount,
                'total_tokens' => $totalTokens,
                'processed_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);

        $this->auditLogger->record(
            action: 'knowledge_document.ready',
            data: [
                'document_id' => $document->id,
                'knowledge_base_id' => $document->knowledge_base_id,
                'chunk_count' => $chunkCount,
                'total_tokens' => $totalTokens,
                'duration_ms' => $durationMs,
            ],
            subjectType: KnowledgeDocument::class,
            subjectId: $document->id,
            tenantId: $document->tenant_id,
        );
    }

    private function validateDocument(KnowledgeDocument $document): void
    {
        if ($document->storage_disk === null || $document->storage_path === null) {
            throw new \RuntimeException('missing_source');
        }

        if ($document->mime_type === null) {
            throw new \RuntimeException('missing_mime_type');
        }
    }

    private function readSource(KnowledgeDocument $document): string
    {
        $disk = $document->storage_disk;
        $path = $document->storage_path;

        if (! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException(self::ERROR_MISSING_SOURCE);
        }

        $content = Storage::disk($disk)->get($path);

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException(self::ERROR_STORAGE_READ);
        }

        return $content;
    }

    private function classifyError(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'missing_source') || str_contains($message, 'could not be read')) {
            return self::ERROR_MISSING_SOURCE;
        }

        if (str_contains($message, 'storage')) {
            return self::ERROR_STORAGE_READ;
        }

        if (str_contains($message, 'text_too_large') || str_contains($message, 'too much text')) {
            return self::ERROR_TEXT_TOO_LARGE;
        }

        if (str_contains($message, 'too_many_chunks') || str_contains($message, 'too many chunks')) {
            return self::ERROR_TOO_MANY_CHUNKS;
        }

        if ($e instanceof DocumentTextTooLargeException) {
            return self::ERROR_TEXT_TOO_LARGE;
        }

        if ($e instanceof DocumentTooManyChunksException) {
            return self::ERROR_TOO_MANY_CHUNKS;
        }

        if ($e instanceof DocumentExtractionFailedException) {
            return self::ERROR_EXTRACTION;
        }

        return self::ERROR_UNKNOWN;
    }
}
