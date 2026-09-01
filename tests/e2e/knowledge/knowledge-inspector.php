<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Services\KnowledgeSearchService;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\Billing\Contracts\UsageGuardInterface;
use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var Application $app */
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenantId = (string) ($argv[1] ?? '');
$knowledgeBaseId = (string) ($argv[2] ?? '');
$documentId = (string) ($argv[3] ?? '');
$query = (string) ($argv[4] ?? '');

try {
    $document = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenantId)
        ->where('knowledge_base_id', $knowledgeBaseId)
        ->where('id', $documentId)
        ->first();

    $chunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenantId)
        ->where('document_id', $documentId)
        ->get();

    $search = new KnowledgeSearchService(
        app(EmbeddingProviderInterface::class),
        app(UsageGuardInterface::class),
    );
    $result = $query === ''
        ? null
        : $search->search($tenantId, $knowledgeBaseId, $query, topK: 5, threshold: 0.0);

    echo json_encode([
        'document_exists' => $document !== null && $document->deleted_at === null,
        'status' => $document?->status->value,
        'source_exists' => $document !== null && Storage::disk($document->storage_disk)->exists($document->storage_path),
        'chunk_count' => $chunks->count(),
        'tenant_ids' => $chunks->pluck('tenant_id')->unique()->values()->all(),
        'document_ids' => $chunks->pluck('document_id')->unique()->values()->all(),
        'embedded_count' => $chunks->filter(fn ($chunk): bool => $chunk->embedding !== null)->count(),
        'vector_dimensions' => $chunks->filter(fn ($chunk): bool => $chunk->embedding !== null)
            ->map(fn ($chunk): ?int => $chunk->embedding === null ? null : count($chunk->embedding))
            ->filter()
            ->unique()
            ->values()
            ->all(),
        'search_count' => $result?->totalCount,
        'search_contents' => $result === null ? [] : array_map(
            static fn ($chunk): string => $chunk->content,
            $result->chunks,
        ),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;
}
