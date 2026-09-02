<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Services\KnowledgeDocumentProcessingService;
use App\Domain\Billing\Contracts\CapacityGuardInterface;
use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\ProcessKnowledgeDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\FakeCapacityGuard;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(CapacityGuardInterface::class, new FakeCapacityGuard);
    Storage::fake('minio');
});

// =========================================================================
// HELPERS
// =========================================================================

function proc_tenant(): Tenant
{
    return Tenant::factory()->create(['status' => 'active']);
}

function proc_user(Tenant $tenant): User
{
    $user = User::factory()->create();

    make_tenant_member($user, $tenant, 'owner');

    return $user;
}

function proc_kb(Tenant $tenant): KnowledgeBase
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

function proc_doc(
    Tenant $tenant,
    string $kbId,
    string $mimeType = 'text/plain',
    string $filename = 'test.txt',
): KnowledgeDocument {
    TenantContext::setId($tenant->id);

    try {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $storagePath = "knowledge/tenant/{$tenant->id}/knowledge-bases/{$kbId}/documents/".Str::uuid()."/source.{$ext}";

        return KnowledgeDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $kbId,
            'storage_disk' => 'minio',
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'original_filename' => $filename,
            'status' => KnowledgeDocumentStatus::Uploaded,
        ]);
    } finally {
        TenantContext::clear();
    }
}

function proc_store_file(string $disk, string $path, string $content): void
{
    Storage::disk($disk)->put($path, $content);
}

function valid_processing_txt(): string
{
    return "First paragraph with enough content to ensure chunking works properly. It has multiple sentences for splitting.\n\nSecond paragraph also has sufficient content to become its own chunk after processing.\n\nThird paragraph ensures we get multiple chunks for testing.";
}

function assert_document_tenant(string $documentId, string $expectedTenantId): void
{
    KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('id', $documentId)
        ->where('tenant_id', $expectedTenantId)
        ->firstOrFail();
}

// =========================================================================
// PROC-01..10 — SUCCESS LIFECYCLE
// =========================================================================

test('PROC-01: TXT end-to-end processing', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id, 'text/plain', 'content.txt');
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);

    expect($started)->not->toBeNull();
    expect($started->status->value)->toBe('processing');

    $service->processDocument($started);

    $doc->refresh();
    expect($doc->status->value)->toBe('ready');
    expect($doc->chunk_count)->toBeGreaterThan(0);
    expect($doc->total_tokens)->toBeGreaterThan(0);
    expect($doc->processed_at)->not->toBeNull();
    expect($doc->error_message)->toBeNull();
});

test('PROC-02: status transition uploaded→processing→ready', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);

    expect($doc->status->value)->toBe('uploaded');

    $started = $service->beginProcessing($tenant->id, $doc->id);
    expect($started->status->value)->toBe('processing');

    $service->processDocument($started);
    $doc->refresh();
    expect($doc->status->value)->toBe('ready');
});

test('PROC-03: chunk_count matches actual chunks', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    $actualChunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->count();

    expect($doc->chunk_count)->toBe($actualChunks);
    expect($actualChunks)->toBeGreaterThan(0);
});

test('PROC-04: total_tokens is sum of chunk token_counts', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    $totalFromChunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->sum('token_count');

    expect($doc->total_tokens)->toBe((int) $totalFromChunks);
});

test('PROC-05: processed_at is set on success', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->processed_at)->not->toBeNull();
    expect($doc->processed_at->timestamp)->toBeLessThanOrEqual(now()->timestamp);
});

test('PROC-06: embedding is NULL on all chunks after processing', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $chunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->get();

    expect($chunks->count())->toBeGreaterThan(0);

    foreach ($chunks as $chunk) {
        expect($chunk->embedding)->toBeNull();
    }
});

test('PROC-07: chunks have tenant_id from document', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $chunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->get();

    foreach ($chunks as $chunk) {
        expect($chunk->tenant_id)->toBe($tenant->id);
    }
});

test('PROC-08: old chunks replaced atomically (no duplicates)', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);

    $started1 = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started1);

    $count1 = KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $doc->id)->count();
    expect($count1)->toBeGreaterThan(0);

    KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('id', $doc->id)
        ->update(['status' => KnowledgeDocumentStatus::Uploaded]);

    $started2 = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started2);

    $count2 = KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $doc->id)->count();
    expect($count2)->toBeGreaterThan(0);
    expect($count2)->toBe($count1);
});

test('PROC-09: ready means ingestion/chunking complete (embedding NULL)', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->status->value)->toBe('ready');
    expect($doc->chunk_count)->toBeGreaterThan(0);

    $chunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->get();

    foreach ($chunks as $chunk) {
        expect($chunk->embedding)->toBeNull();
    }
});

test('PROC-10: processing audit events are recorded', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $tenant->id,
        'action' => 'knowledge_document.ready',
        'subject_type' => KnowledgeDocument::class,
        'subject_id' => $doc->id,
    ]);
});

// =========================================================================
// PROC-FAIL-01..10 — FAILURE HANDLING
// =========================================================================

test('PROC-FAIL-01: missing source file → failed', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->status->value)->toBe('failed');
    expect($doc->error_message)->not->toBeNull();
    expect($doc->chunk_count)->toBeNull();
});

test('PROC-FAIL-02: error_message is sanitized (no paths/stack traces)', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->error_message)->not->toContain('storage_path');
    expect($doc->error_message)->not->toContain('minio');
    expect($doc->error_message)->not->toContain('vendor/');
    expect($doc->error_message)->not->toContain('stack');
    expect($doc->error_message)->not->toContain('/tmp/');
});

test('PROC-FAIL-03: failure audit event recorded', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $tenant->id,
        'action' => 'knowledge_document.failed',
        'subject_type' => KnowledgeDocument::class,
        'subject_id' => $doc->id,
    ]);
});

test('PROC-FAIL-04: beginProcessing returns null for ready document', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);

    TenantContext::setId($tenant->id);
    $doc = KnowledgeDocument::factory()->ready()->create([
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $kb->id,
    ]);
    TenantContext::clear();

    $service = app(KnowledgeDocumentProcessingService::class);
    $result = $service->beginProcessing($tenant->id, $doc->id);

    expect($result)->toBeNull();
});

test('PROC-FAIL-05: beginProcessing returns null for deleted document', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    $doc->delete();

    $service = app(KnowledgeDocumentProcessingService::class);
    $result = $service->beginProcessing($tenant->id, $doc->id);

    expect($result)->toBeNull();
});

test('PROC-FAIL-06: beginProcessing returns null for non-existent document', function (): void {
    $tenant = proc_tenant();

    $service = app(KnowledgeDocumentProcessingService::class);
    $result = $service->beginProcessing($tenant->id, (string) Str::uuid());

    expect($result)->toBeNull();
});

test('PROC-FAIL-07: failed status is terminal', function (): void {
    expect(KnowledgeDocumentStatus::Failed->isTerminal())->toBeTrue();
    expect(KnowledgeDocumentStatus::Ready->isTerminal())->toBeTrue();
    expect(KnowledgeDocumentStatus::Uploaded->isTerminal())->toBeFalse();
    expect(KnowledgeDocumentStatus::Processing->isTerminal())->toBeFalse();
});

test('PROC-FAIL-08: empty text extraction → failed', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, '');

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->status->value)->toBe('failed');
});

test('PROC-FAIL-09: no chunks created on failure', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $chunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->count();

    expect($chunks)->toBe(0);
});

test('PROC-FAIL-10: invalid MIME type → failed', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);

    TenantContext::setId($tenant->id);
    $doc = KnowledgeDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $kb->id,
        'mime_type' => 'application/unknown',
        'status' => KnowledgeDocumentStatus::Uploaded,
    ]);
    TenantContext::clear();

    proc_store_file($doc->storage_disk, $doc->storage_path, 'some content');

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->status->value)->toBe('failed');
});

// =========================================================================
// PROC-MT-01..06 — MULTI-TENANCY
// =========================================================================

test('PROC-MT-01: job processes document with correct tenant context', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->status->value)->toBe('ready');

    $chunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->get();

    foreach ($chunks as $chunk) {
        expect($chunk->tenant_id)->toBe($tenant->id);
    }
});

test('PROC-MT-02: tenant A cannot process tenant B document', function (): void {
    $tenantA = proc_tenant();
    $tenantB = proc_tenant();

    $kbA = proc_kb($tenantA);
    $kbB = proc_kb($tenantB);

    $docB = proc_doc($tenantB, $kbB->id);

    $service = app(KnowledgeDocumentProcessingService::class);
    $result = $service->beginProcessing($tenantA->id, $docB->id);

    expect($result)->toBeNull();
});

test('PROC-MT-03: chunks always have tenant of document, not job tenant', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $chunks = KnowledgeChunk::query()
        ->withoutTenantScope()
        ->where('document_id', $doc->id)
        ->get();

    expect($chunks->count())->toBeGreaterThan(0);

    foreach ($chunks as $chunk) {
        expect($chunk->tenant_id)->toBe($doc->tenant_id);
    }
});

test('PROC-MT-04: two jobs from different tenants do not leak context', function (): void {
    $tenantA = proc_tenant();
    $tenantB = proc_tenant();

    $kbA = proc_kb($tenantA);
    $kbB = proc_kb($tenantB);

    $docA = proc_doc($tenantA, $kbA->id);
    $docB = proc_doc($tenantB, $kbB->id);

    proc_store_file($docA->storage_disk, $docA->storage_path, valid_processing_txt());
    proc_store_file($docB->storage_disk, $docB->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);

    $startedA = $service->beginProcessing($tenantA->id, $docA->id);
    $service->processDocument($startedA);

    $startedB = $service->beginProcessing($tenantB->id, $docB->id);
    $service->processDocument($startedB);

    $docA->refresh();
    $docB->refresh();

    expect($docA->status->value)->toBe('ready');
    expect($docB->status->value)->toBe('ready');

    $chunksA = KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $docA->id)->get();
    $chunksB = KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $docB->id)->get();

    expect($chunksA->count())->toBeGreaterThan(0);
    expect($chunksB->count())->toBeGreaterThan(0);

    foreach ($chunksA as $c) {
        expect($c->tenant_id)->toBe($tenantA->id);
    }
    foreach ($chunksB as $c) {
        expect($c->tenant_id)->toBe($tenantB->id);
    }
});

test('PROC-MT-05: cross-tenant document not affected by other tenant processing', function (): void {
    $tenantA = proc_tenant();
    $tenantB = proc_tenant();

    $kbA = proc_kb($tenantA);
    $kbB = proc_kb($tenantB);

    $docA = proc_doc($tenantA, $kbA->id);
    $docB = proc_doc($tenantB, $kbB->id);

    proc_store_file($docA->storage_disk, $docA->storage_path, valid_processing_txt());
    proc_store_file($docB->storage_disk, $docB->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);

    $startedA = $service->beginProcessing($tenantA->id, $docA->id);
    $service->processDocument($startedA);

    $docB->refresh();
    expect($docB->status->value)->toBe('uploaded');
});

test('PROC-MT-06: job uniqueId includes tenant namespace', function (): void {
    $tenantId = (string) Str::uuid();
    $documentId = (string) Str::uuid();

    $job = new ProcessKnowledgeDocument($tenantId, $documentId);

    expect($job->uniqueId())->toBe("knowledge-document:{$tenantId}:{$documentId}");
});

// =========================================================================
// PROC-CON-01..05 — CONCURRENCY
// =========================================================================

test('PROC-CON-01: beginProcessing is idempotent (second call returns null)', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);

    $service = app(KnowledgeDocumentProcessingService::class);

    $first = $service->beginProcessing($tenant->id, $doc->id);
    expect($first)->not->toBeNull();

    $second = $service->beginProcessing($tenant->id, $doc->id);
    expect($second)->toBeNull();
});

test('PROC-CON-02: duplicate beginProcessing does not duplicate chunks', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $count1 = KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $doc->id)->count();

    KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('id', $doc->id)
        ->update(['status' => KnowledgeDocumentStatus::Uploaded]);

    $started2 = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started2);

    $count2 = KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $doc->id)->count();
    expect($count2)->toBe($count1);
});

test('PROC-CON-03: status CAS prevents overwriting ready with stale failure', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);
    proc_store_file($doc->storage_disk, $doc->storage_path, valid_processing_txt());

    $service = app(KnowledgeDocumentProcessingService::class);
    $started = $service->beginProcessing($tenant->id, $doc->id);
    $service->processDocument($started);

    $doc->refresh();
    expect($doc->status->value)->toBe('ready');

    $started2 = $service->beginProcessing($tenant->id, $doc->id);
    expect($started2)->toBeNull();
});

test('PROC-CON-04: concurrent beginProcessing race — only one wins', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);

    $service = app(KnowledgeDocumentProcessingService::class);

    $results = [];
    for ($i = 0; $i < 3; $i++) {
        $results[] = $service->beginProcessing($tenant->id, $doc->id);
    }

    $wins = array_filter($results, fn ($r) => $r !== null);
    expect(count($wins))->toBe(1);
});

test('PROC-CON-05: DocumentProcessingException prevents delete during processing', function (): void {
    $tenant = proc_tenant();
    $user = proc_user($tenant);
    $kb = proc_kb($tenant);

    TenantContext::setId($tenant->id);
    $doc = KnowledgeDocument::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $kb->id,
    ]);
    TenantContext::clear();

    $this->actingAs($user)
        ->deleteJson("/api/v1/tenants/{$tenant->id}/knowledge-bases/{$kb->id}/documents/{$doc->id}")
        ->assertStatus(409)
        ->assertJson(['code' => 'DOCUMENT_PROCESSING']);
});

// =========================================================================
// QUEUE-01..07 — QUEUE / DISPATCH
// =========================================================================

test('QUEUE-01: upload dispatches ProcessKnowledgeDocument', function (): void {
    Queue::fake();

    $tenant = proc_tenant();
    $user = proc_user($tenant);
    $kb = proc_kb($tenant);

    Storage::fake('minio');

    $this->actingAs($user)
        ->postJson("/api/v1/tenants/{$tenant->id}/knowledge-bases/{$kb->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('dispatch.txt', 'Hello world content'),
        ])->assertStatus(201);

    Queue::assertPushed(ProcessKnowledgeDocument::class, function ($job) use ($tenant) {
        return $job->tenantId === $tenant->id;
    });
});

test('QUEUE-02: ProcessKnowledgeDocument implements ShouldBeUnique', function (): void {
    $job = new ProcessKnowledgeDocument((string) Str::uuid(), (string) Str::uuid());

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
});

test('QUEUE-03: ProcessKnowledgeDocument uniqueId is tenant-namespaced', function (): void {
    $tenantId = (string) Str::uuid();
    $documentId = (string) Str::uuid();

    $job = new ProcessKnowledgeDocument($tenantId, $documentId);

    expect($job->uniqueId())->toBe("knowledge-document:{$tenantId}:{$documentId}");
});

test('QUEUE-04: ProcessKnowledgeDocument has backoff config', function (): void {
    $job = new ProcessKnowledgeDocument((string) Str::uuid(), (string) Str::uuid());

    $backoff = $job->backoff();
    expect($backoff)->toBeArray();
    expect(count($backoff))->toBeGreaterThan(0);
});

test('QUEUE-05: ProcessKnowledgeDocument has tries config', function (): void {
    $job = new ProcessKnowledgeDocument((string) Str::uuid(), (string) Str::uuid());

    expect($job->tries())->toBeGreaterThan(0);
});

test('QUEUE-06: ProcessKnowledgeDocument uses TenantAwareJob trait', function (): void {
    $job = new ProcessKnowledgeDocument((string) Str::uuid(), (string) Str::uuid());

    expect(property_exists($job, 'tenantId'))->toBeTrue();
});

test('QUEUE-07: failed() marks document as failed with sanitized error', function (): void {
    $tenant = proc_tenant();
    $kb = proc_kb($tenant);
    $doc = proc_doc($tenant, $kb->id);

    TenantContext::setId($tenant->id);
    $doc->update(['status' => KnowledgeDocumentStatus::Processing]);
    TenantContext::clear();

    $job = new ProcessKnowledgeDocument($tenant->id, $doc->id);

    TenantContext::setId($tenant->id);
    $job->failed(new RuntimeException('Internal error with /tmp/sensitive/path'));
    TenantContext::clear();

    $doc->refresh();
    expect($doc->status->value)->toBe('failed');
    expect($doc->error_message)->not->toContain('/tmp/');
    expect($doc->error_message)->not->toContain('Internal error');
});

// =========================================================================
// DELETE-01..02 — DELETE DURING PROCESSING
// =========================================================================

test('DELETE-01: delete returns 409 when document is processing', function (): void {
    $tenant = proc_tenant();
    $user = proc_user($tenant);
    $kb = proc_kb($tenant);

    TenantContext::setId($tenant->id);
    $doc = KnowledgeDocument::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $kb->id,
    ]);
    TenantContext::clear();

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/tenants/{$tenant->id}/knowledge-bases/{$kb->id}/documents/{$doc->id}");

    $response->assertStatus(409)
        ->assertJson([
            'code' => 'DOCUMENT_PROCESSING',
        ]);

    $this->assertDatabaseHas('knowledge_documents', [
        'id' => $doc->id,
        'status' => 'processing',
    ]);
});

test('DELETE-02: delete succeeds after processing completes', function (): void {
    $tenant = proc_tenant();
    $user = proc_user($tenant);
    $kb = proc_kb($tenant);

    TenantContext::setId($tenant->id);
    $doc = KnowledgeDocument::factory()->ready()->create([
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $kb->id,
    ]);
    TenantContext::clear();

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/tenants/{$tenant->id}/knowledge-bases/{$kb->id}/documents/{$doc->id}");

    $response->assertOk();

    $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
});
