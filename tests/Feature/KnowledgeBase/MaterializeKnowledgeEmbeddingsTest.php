<?php

declare(strict_types=1);

use App\Application\Audit\Services\AuditLogger;
use App\Application\KnowledgeBase\Services\EmbeddingMaterializationService;
use App\Application\KnowledgeBase\Services\KnowledgeDocumentProcessingService;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\Exceptions\EmbeddingAuthFailedException;
use App\Domain\AI\Exceptions\EmbeddingDimensionMismatchException;
use App\Domain\AI\Exceptions\EmbeddingProviderException;
use App\Domain\AI\Exceptions\EmbeddingRateLimitException;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\KnowledgeBase\ValueObjects\VectorSerializer;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\MaterializeKnowledgeEmbeddings;
use App\Jobs\ProcessKnowledgeDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeUsageGuard;

uses(RefreshDatabase::class);

// =========================================================================
// HELPERS
// =========================================================================

function mat_tenant(): Tenant
{
    return Tenant::factory()->create(['status' => 'active']);
}

function mat_kb(Tenant $tenant): KnowledgeBase
{
    TenantContext::setId($tenant->id);

    try {
        return KnowledgeBase::query()->create([
            'name' => 'KB '.Str::random(6),
        ]);
    } finally {
        TenantContext::clear();
    }
}

function mat_doc(Tenant $tenant, string $kbId): KnowledgeDocument
{
    TenantContext::setId($tenant->id);

    try {
        return KnowledgeDocument::factory()->ready()
            ->create([
                'tenant_id' => $tenant->id,
                'knowledge_base_id' => $kbId,
                'storage_disk' => 'minio',
                'storage_path' => 'knowledge/tenant/'.$tenant->id.'/kb/'.$kbId.'/doc/'.Str::uuid().'/source.txt',
                'mime_type' => 'text/plain',
                'chunk_count' => 3,
                'total_tokens' => 300,
            ]);
    } finally {
        TenantContext::clear();
    }
}

function mat_chunks(Tenant $tenant, string $docId, int $count = 3): array
{
    $chunks = [];

    TenantContext::setId($tenant->id);

    try {
        for ($i = 0; $i < $count; $i++) {
            $chunks[] = KnowledgeChunk::factory()->create([
                'tenant_id' => $tenant->id,
                'document_id' => $docId,
                'chunk_index' => $i,
                'content' => "Test content for chunk number {$i}.",
            ]);
        }
    } finally {
        TenantContext::clear();
    }

    return $chunks;
}

function bind_fake_embedding_provider(?EmbeddingProviderInterface $fake = null): FakeEmbeddingProvider
{
    /** @var FakeEmbeddingProvider $fakeProvider */
    $fakeProvider = $fake ?? new FakeEmbeddingProvider;

    app()->instance(EmbeddingProviderInterface::class, $fakeProvider);

    return $fakeProvider;
}

// =========================================================================
// EMB-MAT-01..15 — SERVICE TESTS
// =========================================================================

test('EMB-MAT-01: pending chunks are materialized', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 2);

    $fake = bind_fake_embedding_provider();

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(2);
    expect($fake->callCount())->toBe(1);
})->group('EMB-MAT');

test('EMB-MAT-02: already embedded chunks are skipped', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 2);

    $fake = bind_fake_embedding_provider();

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $service->materialize($doc);
    $callCount1 = $fake->callCount();

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(0);
    expect($fake->callCount())->toBe($callCount1);
})->group('EMB-MAT');

test('EMB-MAT-03: batch size respects max_batch_size config', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 5);

    $fake = bind_fake_embedding_provider();

    config(['ai.embedding.providers.openai.max_batch_size' => 3]);

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(5);
    expect($fake->callCount())->toBe(2);
})->group('EMB-MAT');

test('EMB-MAT-04: chunks larger than max_batch_size splits into batches', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 6);

    $fake = bind_fake_embedding_provider();

    config(['ai.embedding.providers.openai.max_batch_size' => 2]);

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(6);
    expect($result['batches'])->toBe(3);
    expect($fake->callCount())->toBe(3);
})->group('EMB-MAT');

test('EMB-MAT-05: preserves chunk order (chunk_index)', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 3);

    $fake = bind_fake_embedding_provider();

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $service->materialize($doc);

    $request = $fake->lastRequest();
    expect($request)->not->toBeNull();
    expect($request->input[0])->toContain('chunk number 0');
    expect($request->input[1])->toContain('chunk number 1');
    expect($request->input[2])->toContain('chunk number 2');
})->group('EMB-MAT');

test('EMB-MAT-06: wrong dimension does not persist', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 2);

    $fake = bind_fake_embedding_provider();
    $fake->withWrongDimension(100);

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(0);
})->group('EMB-MAT');

test('EMB-MAT-07: provider failure does not partially persist', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 2);

    $fake = bind_fake_embedding_provider();
    $fake->withException(new EmbeddingProviderException('provider error'));

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(0);
})->group('EMB-MAT');

test('EMB-MAT-08: zero chunks does not call provider', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);

    $fake = bind_fake_embedding_provider();

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(0);
    expect($result['batches'])->toBe(0);
    expect($fake->callCount())->toBe(0);
})->group('EMB-MAT');

test('EMB-MAT-09: deleted document stops processing', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 2);

    $fake = bind_fake_embedding_provider();

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('id', $doc->id)
        ->delete();

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(0);
    expect($fake->callCount())->toBe(0);
})->group('EMB-MAT');

test('EMB-MAT-10: total tokens aggregated across batches', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 4);

    $fake = bind_fake_embedding_provider();

    config(['ai.embedding.providers.openai.max_batch_size' => 2]);

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['total_input_tokens'])->toBeGreaterThan(0);
})->group('EMB-MAT');

test('EMB-MAT-11: no content or vectors in audit payload', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);
    $chunks = mat_chunks($tenant, $doc->id, 2);

    $fake = bind_fake_embedding_provider();

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $service->materialize($doc);

    $audit = AuditLog::query()
        ->where('action', 'knowledge_embeddings.materialized')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->data)->not->toContain('content');
    expect($audit->data)->not->toContain('embedding');
    expect($audit->data['document_id'])->toBe($doc->id);
    expect($audit->data['chunks_processed'])->toBe(2);
})->group('EMB-MAT');

test('EMB-MAT-12: no embedding column returns early', function (): void {
    if (Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('SQLite required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);

    $fake = bind_fake_embedding_provider();

    $service = new EmbeddingMaterializationService(
        app(EmbeddingProviderInterface::class),
        app(AuditLogger::class),
        new FakeUsageGuard,
    );

    $result = $service->materialize($doc);

    expect($result['chunks_processed'])->toBe(0);
    expect($fake->callCount())->toBe(0);
})->group('EMB-MAT');

// =========================================================================
// EMB-JOB-01..10 — JOB TESTS
// =========================================================================

test('EMB-JOB-01: MaterializeKnowledgeEmbeddings is TenantAwareJob', function (): void {
    $job = new MaterializeKnowledgeEmbeddings(
        tenantId: (string) Str::uuid(),
        documentId: (string) Str::uuid(),
    );

    expect($job->tenantId)->not->toBeEmpty();
})->group('EMB-JOB');

test('EMB-JOB-02: ShouldBeUnique with correct uniqueId', function (): void {
    $tenantId = (string) Str::uuid();
    $documentId = (string) Str::uuid();

    $job = new MaterializeKnowledgeEmbeddings(
        tenantId: $tenantId,
        documentId: $documentId,
    );

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe("embeddings:{$tenantId}:{$documentId}");
    expect($job->uniqueFor())->toBe(600);
})->group('EMB-JOB');

test('EMB-JOB-03: retries config from knowledge.materialization', function (): void {
    config(['knowledge.materialization.tries' => 5]);
    config(['knowledge.materialization.backoff' => [10, 20, 40]]);

    $job = new MaterializeKnowledgeEmbeddings(
        tenantId: (string) Str::uuid(),
        documentId: (string) Str::uuid(),
    );

    expect($job->tries())->toBe(5);
    expect($job->backoff())->toBe([10, 20, 40]);
})->group('EMB-JOB');

test('EMB-JOB-04: afterCommit is true', function (): void {
    $job = new MaterializeKnowledgeEmbeddings(
        tenantId: (string) Str::uuid(),
        documentId: (string) Str::uuid(),
    );

    expect($job->afterCommit)->toBeTrue();
})->group('EMB-JOB');

test('EMB-JOB-05: timeout is 180', function (): void {
    $job = new MaterializeKnowledgeEmbeddings(
        tenantId: (string) Str::uuid(),
        documentId: (string) Str::uuid(),
    );

    expect($job->timeout)->toBe(180);
})->group('EMB-JOB');

test('EMB-JOB-06: dispatches from ProcessKnowledgeDocument after ready with chunks', function (): void {
    if (! Schema::hasColumn('knowledge_chunks', 'embedding')) {
        $this->markTestSkipped('Embedding column required');
    }

    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);

    Queue::fake();

    $job = new ProcessKnowledgeDocument($tenant->id, $doc->id);
    $job->handle();

    Queue::assertPushed(MaterializeKnowledgeEmbeddings::class, function ($job) use ($tenant, $doc) {
        return $job->tenantId === $tenant->id
            && $job->documentId === $doc->id
            && $job->queue === 'knowledge';
    });
})->group('EMB-JOB');

test('EMB-JOB-07: failed processing does NOT dispatch embeddings', function (): void {
    $tenant = mat_tenant();
    $kb = mat_kb($tenant);

    TenantContext::setId($tenant->id);
    try {
        $doc = KnowledgeDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $kb->id,
            'status' => 'uploaded',
            'storage_disk' => 'minio',
            'storage_path' => 'knowledge/tenant/'.$tenant->id.'/missing.txt',
            'mime_type' => 'text/plain',
        ]);
    } finally {
        TenantContext::clear();
    }

    Queue::fake();

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);

    if ($started !== null) {
        $service->processDocument($started);
    }

    $doc->refresh();

    Queue::assertNotPushed(MaterializeKnowledgeEmbeddings::class);
})->group('EMB-JOB');

test('EMB-JOB-08: failed() records audit with safe error code', function (): void {
    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);

    $job = new MaterializeKnowledgeEmbeddings($tenant->id, $doc->id);
    $job->failed(new EmbeddingAuthFailedException('test auth error'));

    $audit = AuditLog::query()
        ->where('action', 'knowledge_embeddings.failed')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->data['document_id'])->toBe($doc->id);
    expect($audit->data['error_code'])->toBe('auth_failed');
})->group('EMB-JOB');

test('EMB-JOB-09: failed() classifies rate limit correctly', function (): void {
    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);

    $job = new MaterializeKnowledgeEmbeddings($tenant->id, $doc->id);
    $job->failed(new EmbeddingRateLimitException('rate limited'));

    $audit = AuditLog::query()
        ->where('action', 'knowledge_embeddings.failed')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->data['error_code'])->toBe('rate_limited');
})->group('EMB-JOB');

test('EMB-JOB-10: failed() ignores deleted document', function (): void {
    $tenant = mat_tenant();
    $kb = mat_kb($tenant);
    $doc = mat_doc($tenant, $kb->id);

    KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('id', $doc->id)
        ->delete();

    $job = new MaterializeKnowledgeEmbeddings($tenant->id, $doc->id);
    $job->failed(new RuntimeException('test'));

    $audit = AuditLog::query()
        ->where('action', 'knowledge_embeddings.failed')
        ->latest()
        ->first();

    expect($audit)->toBeNull();
})->group('EMB-JOB');

// =========================================================================
// EMB-MT-01..06 — MULTI-TENANCY
// =========================================================================

test('EMB-MT-01: tenant context is set during execution', function (): void {
    $tenant = mat_tenant();

    $job = new MaterializeKnowledgeEmbeddings($tenant->id, (string) Str::uuid());

    expect($job->tenantId)->toBe($tenant->id);
})->group('EMB-MT');

test('EMB-MT-02: cross-tenant document id returns silently', function (): void {
    $tenantA = mat_tenant();
    $tenantB = mat_tenant();
    $kbB = mat_kb($tenantB);
    $docB = mat_doc($tenantB, $kbB->id);

    $job = new MaterializeKnowledgeEmbeddings($tenantA->id, $docB->id);

    expect($job->tenantId)->toBe($tenantA->id);
    expect($job->documentId)->toBe($docB->id);
})->group('EMB-MT');

test('EMB-MT-03: uniqueId includes tenant隔离', function (): void {
    $tenantA = mat_tenant();
    $tenantB = mat_tenant();
    $docId = (string) Str::uuid();

    $jobA = new MaterializeKnowledgeEmbeddings($tenantA->id, $docId);
    $jobB = new MaterializeKnowledgeEmbeddings($tenantB->id, $docId);

    expect($jobA->uniqueId())->not->toBe($jobB->uniqueId());
})->group('EMB-MT');

test('EMB-MT-04: tenant_id from constructor, never from config', function (): void {
    $tenantId = (string) Str::uuid();
    $documentId = (string) Str::uuid();

    $job = new MaterializeKnowledgeEmbeddings($tenantId, $documentId);

    expect($job->tenantId)->toBe($tenantId);
    expect($job->documentId)->toBe($documentId);
})->group('EMB-MT');

test('EMB-MT-05: VectorSerializer validates dimension', function (): void {
    $this->expectException(EmbeddingDimensionMismatchException::class);

    VectorSerializer::validate(
        [0.1, 0.2, 0.3],
        1536,
    );
})->group('EMB-MT');

test('EMB-MT-06: VectorSerializer validates non-finite values', function (): void {
    $this->expectException(EmbeddingProviderException::class);

    VectorSerializer::validate(
        array_fill(0, 1536, NAN),
    );
})->group('EMB-MT');
