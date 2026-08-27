<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Services\KnowledgeSearchService;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\ValueObjects\EmbeddingRequest;
use App\Domain\KnowledgeBase\ValueObjects\VectorSerializer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Semantic Search Tests (FASE 17 U3.3)
|--------------------------------------------------------------------------
|
| RAG-PG-01..10 + RAG-MT-01..07 — Vector cosine search real sobre pgvector.
|
| Estos tests REQUIEREN PostgreSQL + extensión pgvector activa.
| Ejecutar con: php artisan test --group=RAG-PG
|
*/

class KnowledgeSearchPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    private string $kbId;

    private string $docId;

    private FakeEmbeddingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required');
        }

        $this->tenantId = $this->createTestTenant('Search Test Tenant');
        $this->kbId = $this->createTestKb();
        $this->docId = $this->createTestDocument();
        $this->fake = new FakeEmbeddingProvider;

        app()->instance(EmbeddingProviderInterface::class, $this->fake);
    }

    private function createTestTenant(string $name = 'Test Tenant'): string
    {
        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $name,
            'slug' => 'test-'.strtolower(Str::random(8)),
            'status' => 'active',
            'timezone' => 'UTC',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function createTestKb(): string
    {
        $kbId = (string) Str::uuid();
        DB::table('knowledge_bases')->insert([
            'id' => $kbId,
            'tenant_id' => $this->tenantId,
            'name' => 'Test KB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $kbId;
    }

    private function createTestDocument(): string
    {
        $docId = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $docId,
            'tenant_id' => $this->tenantId,
            'knowledge_base_id' => $this->kbId,
            'original_filename' => 'test.txt',
            'storage_disk' => 'minio',
            'storage_path' => 'test/path.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'file_hash' => bin2hex(random_bytes(32)),
            'status' => 'ready',
            'chunk_count' => 3,
            'total_tokens' => 300,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $docId;
    }

    private function insertChunkRaw(
        string $documentId,
        int $index,
        string $content,
        string $vectorText,
        ?string $tenantId = null,
    ): string {
        $chunkId = (string) Str::uuid();
        DB::statement(
            'INSERT INTO knowledge_chunks (id, tenant_id, document_id, content, chunk_index, token_count, metadata, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::vector, ?, ?)',
            [
                $chunkId,
                $tenantId ?? $this->tenantId,
                $documentId,
                $content,
                $index,
                (int) ceil(strlen($content) / 4),
                '{"test":true}',
                $vectorText,
                now()->toDateTimeString(),
                now()->toDateTimeString(),
            ],
        );

        return $chunkId;
    }

    private function searchQueryFor(string $text): array
    {
        $response = $this->fake->embed(
            new EmbeddingRequest(input: [$text]),
        );

        return $response->embeddings[0];
    }

    private function createOtherTenantWithKbAndDoc(string $name): array
    {
        $tenantId = $this->createTestTenant($name);
        $kbId = (string) Str::uuid();
        DB::table('knowledge_bases')->insert([
            'id' => $kbId,
            'tenant_id' => $tenantId,
            'name' => $name.' KB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $docId = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $docId,
            'tenant_id' => $tenantId,
            'knowledge_base_id' => $kbId,
            'original_filename' => strtolower(str_replace(' ', '_', $name)).'.txt',
            'storage_disk' => 'minio',
            'storage_path' => $name.'/path.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'file_hash' => bin2hex(random_bytes(32)),
            'status' => 'ready',
            'chunk_count' => 1,
            'total_tokens' => 100,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['tenant_id' => $tenantId, 'kb_id' => $kbId, 'doc_id' => $docId];
    }

    private function createDeletedDocumentInSameKb(): string
    {
        $docId = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $docId,
            'tenant_id' => $this->tenantId,
            'knowledge_base_id' => $this->kbId,
            'original_filename' => 'deleted.txt',
            'storage_disk' => 'minio',
            'storage_path' => 'deleted/path.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'file_hash' => bin2hex(random_bytes(32)),
            'status' => 'ready',
            'chunk_count' => 1,
            'total_tokens' => 100,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        return $docId;
    }

    // ============================================================
    // RAG-PG-01: Cosine ordering — exact match scores higher
    // ============================================================

    public function test_cosine_ordering_exact_match_scores_first(): void
    {
        $queryText = 'laravel php framework';

        $queryVector = $this->searchQueryFor($queryText);
        $otherVector = $this->searchQueryFor('python machine learning');

        $this->insertChunkRaw($this->docId, 0, 'Other topic', VectorSerializer::serialize($otherVector));
        $this->insertChunkRaw($this->docId, 1, 'Laravel PHP Framework', VectorSerializer::serialize($queryVector));

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: $queryText,
            topK: 5,
        );

        $this->assertNotEmpty($result->chunks);
        $this->assertSame('Laravel PHP Framework', $result->chunks[0]->content);
        $this->assertGreaterThan($result->chunks[1]->score ?? 0.0, $result->chunks[0]->score);
    }

    // ============================================================
    // RAG-PG-02 + RAG-MT-01: Tenant filter
    // ============================================================

    public function test_tenant_filter_only_returns_own_tenant_chunks(): void
    {
        $other = $this->createOtherTenantWithKbAndDoc('Other Tenant');

        $queryVector = $this->searchQueryFor('test content');
        $this->insertChunkRaw($other['doc_id'], 0, 'Other tenant content', VectorSerializer::serialize($queryVector), $other['tenant_id']);

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'test content',
            topK: 5,
        );

        $this->assertEmpty($result->chunks);
    }

    // ============================================================
    // RAG-PG-03: KB filter — only chunks from target KB
    // ============================================================

    public function test_kb_filter_only_returns_chunks_from_specified_kb(): void
    {
        $otherKbId = (string) Str::uuid();
        DB::table('knowledge_bases')->insert([
            'id' => $otherKbId,
            'tenant_id' => $this->tenantId,
            'name' => 'Other KB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDocId = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $otherDocId,
            'tenant_id' => $this->tenantId,
            'knowledge_base_id' => $otherKbId,
            'original_filename' => 'other.txt',
            'storage_disk' => 'minio',
            'storage_path' => 'other/path.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'file_hash' => bin2hex(random_bytes(32)),
            'status' => 'ready',
            'chunk_count' => 1,
            'total_tokens' => 100,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queryVector = $this->searchQueryFor('shared topic');
        $this->insertChunkRaw($otherDocId, 0, 'Other KB content', VectorSerializer::serialize($queryVector));

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'shared topic',
            topK: 5,
        );

        $this->assertEmpty($result->chunks);
    }

    // ============================================================
    // RAG-PG-04: LIMIT respected
    // ============================================================

    public function test_topk_limit_respected(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $chunkVector = $this->searchQueryFor("unique content item {$i}");
            $this->insertChunkRaw($this->docId, $i, "Chunk {$i}", VectorSerializer::serialize($chunkVector));
        }

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'filler',
            topK: 3,
        );

        $this->assertCount(3, $result->chunks);
        $this->assertSame(3, $result->topK);
    }

    // ============================================================
    // RAG-PG-05: Threshold filters low similarity
    // ============================================================

    public function test_threshold_filters_low_similarity_chunks(): void
    {
        $exactVector = $this->searchQueryFor('machine learning algorithms');
        $distantVector = $this->searchQueryFor('unrelated cooking recipes');

        $this->insertChunkRaw($this->docId, 0, 'ML algorithms', VectorSerializer::serialize($exactVector));
        $this->insertChunkRaw($this->docId, 1, 'Cooking recipes', VectorSerializer::serialize($distantVector));

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'machine learning algorithms',
            topK: 10,
            threshold: 0.9,
        );

        $this->assertNotEmpty($result->chunks);
        $this->assertSame('ML algorithms', $result->chunks[0]->content);
    }

    // ============================================================
    // RAG-PG-06: HNSW index exists and cosine query is compatible
    // ============================================================

    public function test_hnsw_index_exists_and_cosine_query_compatible(): void
    {
        $hnswIndex = DB::select("
            SELECT
                i.relname        AS index_name,
                am.amname        AS access_method,
                ix.indisvalid    AS is_valid,
                oc.opcname       AS operator_class,
                a.attname        AS column_name
            FROM pg_index ix
            JOIN pg_class i     ON i.oid = ix.indexrelid
            JOIN pg_class t     ON t.oid = ix.indrelid
            JOIN pg_am am       ON am.oid = i.relam
            JOIN pg_attribute a ON a.attrelid = ix.indrelid
                              AND a.attnum = ANY(ix.indkey)
            JOIN pg_opclass oc  ON oc.oid = ix.indclass[0]
            WHERE t.relname = 'knowledge_chunks'
              AND a.attname = 'embedding'
              AND am.amname = 'hnsw'
        ");

        $this->assertNotEmpty($hnswIndex, 'knowledge_chunks debe tener un índice HNSW sobre la columna embedding.');
        $this->assertSame('hnsw', $hnswIndex[0]->access_method);
        $this->assertSame('vector_cosine_ops', $hnswIndex[0]->operator_class);
        $this->assertSame('embedding', $hnswIndex[0]->column_name);
        $this->assertTrue((bool) $hnswIndex[0]->is_valid);

        $queryVector = $this->searchQueryFor('index test');
        $this->insertChunkRaw($this->docId, 0, 'Indexed chunk', VectorSerializer::serialize($queryVector));

        $results = DB::select(
            'SELECT 1 AS compatible FROM knowledge_chunks WHERE embedding IS NOT NULL ORDER BY embedding <=> ?::vector LIMIT 1',
            [VectorSerializer::serialize($queryVector)],
        );

        $this->assertNotEmpty($results);
        $this->assertSame(1, $results[0]->compatible);
    }

    // ============================================================
    // RAG-PG-07 + RAG-MT-05: Deleted document excluded
    // ============================================================

    public function test_deleted_document_chunks_excluded_from_search(): void
    {
        $deletedDocId = $this->createDeletedDocumentInSameKb();

        $queryVector = $this->searchQueryFor('deleted doc query');
        $this->insertChunkRaw($deletedDocId, 0, 'Deleted doc chunk', VectorSerializer::serialize($queryVector));

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'deleted doc query',
            topK: 5,
        );

        $this->assertEmpty($result->chunks);
    }

    // ============================================================
    // RAG-PG-08: NULL embedding ignored
    // ============================================================

    public function test_null_embedding_chunks_ignored(): void
    {
        $chunkId = (string) Str::uuid();
        DB::statement(
            'INSERT INTO knowledge_chunks (id, tenant_id, document_id, content, chunk_index, token_count, metadata, embedding, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, NULL, ?, ?)',
            [
                $chunkId,
                $this->tenantId,
                $this->docId,
                'Pending embedding chunk',
                0,
                50,
                '{"test":true}',
                now()->toDateTimeString(),
                now()->toDateTimeString(),
            ],
        );

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'pending embedding query',
            topK: 5,
        );

        $this->assertEmpty($result->chunks);
    }

    // ============================================================
    // RAG-PG-09: Tie ordering — stable by document_id, chunk_index, id
    // ============================================================

    public function test_tie_ordering_is_deterministic(): void
    {
        $queryVector = $this->searchQueryFor('tie breaker test');

        for ($i = 0; $i < 3; $i++) {
            $this->insertChunkRaw($this->docId, $i, "Tie chunk {$i}", VectorSerializer::serialize($queryVector));
        }

        $service = app(KnowledgeSearchService::class);

        $run1 = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'tie breaker test',
            topK: 3,
        );

        $run2 = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'tie breaker test',
            topK: 3,
        );

        $ids1 = array_map(fn ($c) => $c->chunkId, $run1->chunks);
        $ids2 = array_map(fn ($c) => $c->chunkId, $run2->chunks);

        $this->assertSame($ids1, $ids2);
    }

    // ============================================================
    // RAG-PG-10: Vector parameterized — no DB::raw interpolation
    // ============================================================

    public function test_vector_passed_via_parameterized_binding(): void
    {
        $validVector = $this->searchQueryFor('valid binding control');
        $this->insertChunkRaw($this->docId, 0, 'Bound vector control', VectorSerializer::serialize($validVector));

        $rowCountBefore = (int) DB::table('knowledge_chunks')->count();

        $maliciousVector = '1.0,2.0,3.0]::vector; DROP TABLE knowledge_chunks; --';

        $caught = null;
        DB::statement('SAVEPOINT reject_invalid_vector');
        try {
            DB::select(
                'SELECT 1 AS safe FROM knowledge_chunks WHERE embedding IS NOT NULL ORDER BY embedding <=> ?::vector LIMIT 1',
                [$maliciousVector],
            );
        } catch (QueryException $e) {
            $caught = $e;
            DB::statement('ROLLBACK TO SAVEPOINT reject_invalid_vector');
        }
        DB::statement('RELEASE SAVEPOINT reject_invalid_vector');

        $this->assertNotNull($caught, 'El vector inválido debe rechazarse como tipo por PostgreSQL.');
        $this->assertStringContainsString('22P02', (string) $caught->getCode());

        $tableExists = DB::select("SELECT 1 AS exists FROM information_schema.tables WHERE table_name = 'knowledge_chunks'");
        $this->assertNotEmpty($tableExists);

        $rowCountAfter = (int) DB::table('knowledge_chunks')->count();
        $this->assertSame($rowCountBefore, $rowCountAfter);

        $control = DB::select(
            'SELECT 1 AS safe FROM knowledge_chunks WHERE embedding IS NOT NULL ORDER BY embedding <=> ?::vector LIMIT 1',
            [VectorSerializer::serialize($validVector)],
        );
        $this->assertNotEmpty($control);
    }

    // ============================================================
    // RAG-MT-03: Cross-tenant query returns no results
    // ============================================================

    public function test_tenant_a_query_never_returns_tenant_b_chunks(): void
    {
        $other = $this->createOtherTenantWithKbAndDoc('Tenant B');

        $sharedVector = $this->searchQueryFor('identical content');
        $this->insertChunkRaw($other['doc_id'], 0, 'Tenant B secret', VectorSerializer::serialize($sharedVector), $other['tenant_id']);

        $service = app(KnowledgeSearchService::class);
        $result = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'identical content',
            topK: 5,
        );

        $this->assertEmpty($result->chunks);
    }

    // ============================================================
    // RAG-MT-04: Same content in different tenants is isolated
    // ============================================================

    public function test_identical_content_in_different_tenants_is_isolated(): void
    {
        $other = $this->createOtherTenantWithKbAndDoc('Tenant C');

        $sharedVector = $this->searchQueryFor('shared secret topic');
        $this->insertChunkRaw($this->docId, 0, 'Tenant A secret', VectorSerializer::serialize($sharedVector));
        $this->insertChunkRaw($other['doc_id'], 0, 'Tenant C secret', VectorSerializer::serialize($sharedVector), $other['tenant_id']);

        $service = app(KnowledgeSearchService::class);
        $resultA = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'shared secret topic',
            topK: 5,
        );

        $this->assertCount(1, $resultA->chunks);
        $this->assertSame('Tenant A secret', $resultA->chunks[0]->content);
    }

    // ============================================================
    // RAG-MT-06: TenantContext switch A→B prevents data leak
    // ============================================================

    public function test_switching_tenant_context_does_not_leak_data(): void
    {
        $other = $this->createOtherTenantWithKbAndDoc('Tenant D');

        $vector = $this->searchQueryFor('tenant D content');
        $this->insertChunkRaw($other['doc_id'], 0, 'Tenant D only', VectorSerializer::serialize($vector), $other['tenant_id']);

        $service = app(KnowledgeSearchService::class);

        $resultA = $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: $this->kbId,
            query: 'tenant D content',
            topK: 5,
        );

        $resultB = $service->search(
            tenantId: $other['tenant_id'],
            knowledgeBaseId: $other['kb_id'],
            query: 'tenant D content',
            topK: 5,
        );

        $this->assertEmpty($resultA->chunks);
        $this->assertCount(1, $resultB->chunks);
        $this->assertSame('Tenant D only', $resultB->chunks[0]->content);
    }

    // ============================================================
    // RAG-MT-07: Manipulated KB UUID returns fail closed
    // ============================================================

    public function test_manipulated_kb_uuid_returns_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Knowledge base not found');

        $service = app(KnowledgeSearchService::class);
        $service->search(
            tenantId: $this->tenantId,
            knowledgeBaseId: '00000000-0000-0000-0000-000000000099',
            query: 'test',
        );
    }
}
