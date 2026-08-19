<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Services\KnowledgeSearchService;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| KnowledgeSearchService Feature Tests (FASE 17 U3.3)
|--------------------------------------------------------------------------
|
| RAG-S-11..12..03..04..13..15 — Validation, config, provider call.
| RAG-S-30 — SQL injection safety (parameterized binding).
| RAG-MT-02..07 — Tenant/KB resolution safety.
|
| Estos tests corren en SQLite (validation + config + safety).
| Cosine search real con pgvector es RAG-PG-*.
|
*/

uses(RefreshDatabase::class);
uses()->group('RAG');

beforeEach(function (): void {
    $this->fake = new FakeEmbeddingProvider;
    app()->instance(EmbeddingProviderInterface::class, $this->fake);

    $this->tenantId = (string) Str::uuid();
    $this->kbId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $this->tenantId,
        'name' => 'Test Tenant',
        'slug' => 'test-'.strtolower(Str::random(8)),
        'status' => 'active',
        'timezone' => 'UTC',
        'locale' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('knowledge_bases')->insert([
        'id' => $this->kbId,
        'tenant_id' => $this->tenantId,
        'name' => 'Test KB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('knowledge_documents')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $this->tenantId,
        'knowledge_base_id' => $this->kbId,
        'original_filename' => 'test.txt',
        'storage_disk' => 'minio',
        'storage_path' => 'test/path.txt',
        'mime_type' => 'text/plain',
        'file_size' => 100,
        'file_hash' => bin2hex(random_bytes(32)),
        'status' => 'ready',
        'chunk_count' => 0,
        'total_tokens' => 0,
        'processed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('rejects empty query with invalid argument exception', function (): void {
    $service = app(KnowledgeSearchService::class);
    $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: '   ',
    );
})->throws(InvalidArgumentException::class, 'Search query must not be empty.')
    ->group('RAG-S-11');

it('rejects whitespace-only query with invalid argument exception', function (): void {
    $service = app(KnowledgeSearchService::class);
    $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: "\t\n\r  ",
    );
})->throws(InvalidArgumentException::class, 'Search query must not be empty.')
    ->group('RAG-S-11');

it('rejects oversized query with invalid argument exception', function (): void {
    config(['knowledge.search.max_query_length' => 100]);

    $service = app(KnowledgeSearchService::class);
    $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: str_repeat('a', 101),
    );
})->throws(InvalidArgumentException::class, 'Search query exceeds maximum length')
    ->group('RAG-S-12');

it('rejects topK below 1 with invalid argument exception', function (): void {
    $service = app(KnowledgeSearchService::class);
    $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'test',
        topK: 0,
    );
})->throws(InvalidArgumentException::class, 'topK must be between')
    ->group('RAG-S-03');

it('rejects topK above hard max with invalid argument exception', function (): void {
    $service = app(KnowledgeSearchService::class);
    $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'test',
        topK: 21,
    );
})->throws(InvalidArgumentException::class, 'topK must be between')
    ->group('RAG-S-03');

it('rejects threshold outside 0..1 range with invalid argument exception', function (): void {
    $service = app(KnowledgeSearchService::class);
    $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'test',
        threshold: 1.5,
    );
})->throws(InvalidArgumentException::class, 'Threshold must be between')
    ->group('RAG-S-04');

it('rejects negative threshold with invalid argument exception', function (): void {
    $service = app(KnowledgeSearchService::class);
    $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'test',
        threshold: -0.1,
    );
})->throws(InvalidArgumentException::class, 'Threshold must be between')
    ->group('RAG-S-04');

it('returns empty result for non-existent knowledge base on non-pgsql', function (): void {
    $service = app(KnowledgeSearchService::class);
    $result = $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: '00000000-0000-0000-0000-000000000099',
        query: 'test query',
    );

    expect($result->isEmpty())->toBeTrue();
})->group('RAG-MT-02');

it('returns empty result on non-pgsql without calling embedding provider', function (): void {
    $service = app(KnowledgeSearchService::class);
    $result = $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'semantic search test',
    );

    expect($result->isEmpty())->toBeTrue()
        ->and($this->fake->callCount())->toBe(0);
})->group('RAG-S-13');

it('SQL injection in query does not crash the service', function (): void {
    $service = app(KnowledgeSearchService::class);
    $result = $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: "' OR 1=1 --",
    );

    expect($result->isEmpty())->toBeTrue();
})->group('RAG-S-30');

it('SQL injection in tenantId does not crash the service', function (): void {
    $service = app(KnowledgeSearchService::class);
    $result = $service->search(
        tenantId: "'; DROP TABLE knowledge_chunks; --",
        knowledgeBaseId: $this->kbId,
        query: 'test',
    );

    expect($result->isEmpty())->toBeTrue();
})->group('RAG-MT-07');

it('uses default topK from config when not specified', function (): void {
    config(['knowledge.search.default_top_k' => 10]);

    $service = app(KnowledgeSearchService::class);
    $result = $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'test',
    );

    expect($result->topK)->toBe(10);
})->group('RAG-S-03');

it('returns KnowledgeSearchResult with correct metadata', function (): void {
    $service = app(KnowledgeSearchService::class);
    $result = $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'test',
        topK: 3,
        threshold: 0.7,
    );

    expect($result->topK)->toBe(3)
        ->and($result->threshold)->toBe(0.7)
        ->and($result->searchDurationMs)->toBeFloat()
        ->and($result->query)->toBe('test');
})->group('RAG-S-15');

it('context limit config is respected as zero returns empty', function (): void {
    config(['knowledge.search.max_context_chars' => 0]);

    $service = app(KnowledgeSearchService::class);
    $result = $service->search(
        tenantId: $this->tenantId,
        knowledgeBaseId: $this->kbId,
        query: 'test',
    );

    expect($result->chunks)->toBe([]);
})->group('RAG-S-09');
