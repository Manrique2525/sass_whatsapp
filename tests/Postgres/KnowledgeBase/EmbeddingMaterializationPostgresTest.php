<?php

declare(strict_types=1);

use App\Application\Audit\Services\AuditLogger;
use App\Application\KnowledgeBase\Services\EmbeddingMaterializationService;
use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\FakeUsageGuard;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Embedding Materialization Tests (FASE 17 U3.2)
|--------------------------------------------------------------------------
|
| EMB-PG-01..10 — Vector persistence, CAS, transactions, HNSW, tenancy.
|
| Estos tests REQUIEREN PostgreSQL + extensión pgvector activa.
| Ejecutar con: php artisan test --group=EMB-PG
|
*/

class EmbeddingMaterializationPostgresTest extends PgvectorTestCase
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

        $this->tenantId = $this->createTestTenant('Embedding Test Tenant');
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
            'chunk_count' => 2,
            'total_tokens' => 200,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $docId;
    }

    private function createChunks(int $count = 2, bool $withEmbedding = false): array
    {
        $chunks = [];

        for ($i = 0; $i < $count; $i++) {
            $chunkId = (string) Str::uuid();
            $row = [
                'id' => $chunkId,
                'tenant_id' => $this->tenantId,
                'document_id' => $this->docId,
                'content' => "Test content chunk {$i}",
                'token_count' => 100,
                'chunk_index' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($withEmbedding) {
                $row['embedding'] = '['.str_repeat('0.1,', 1535).'0.1]';
            } else {
                $row['embedding'] = null;
            }

            DB::table('knowledge_chunks')->insert($row);
            $chunks[] = $chunkId;
        }

        return $chunks;
    }

    private function getService(): EmbeddingMaterializationService
    {
        return new EmbeddingMaterializationService(
            $this->fake,
            app(AuditLogger::class),
            new FakeUsageGuard,
        );
    }

    private function getDocument()
    {
        return KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('id', $this->docId)
            ->first();
    }

    public function test_emb_pg_01_persist_vector_1536(): void
    {
        $this->createChunks(2, false);

        $service = $this->getService();
        $document = $this->getDocument();

        $result = $service->materialize($document);

        $this->assertEquals(2, $result['chunks_processed']);

        $chunks = DB::table('knowledge_chunks')
            ->where('document_id', $this->docId)
            ->orderBy('chunk_index')
            ->get();

        foreach ($chunks as $chunk) {
            $this->assertNotNull($chunk->embedding);
            $embedding = DB::select('SELECT embedding::text AS txt FROM knowledge_chunks WHERE id = ?', [$chunk->id]);
            $this->assertNotEmpty($embedding);
            $parsed = json_decode('['.trim($embedding[0]->txt, '[]').']');
            $this->assertCount(1536, $parsed);
        }
    }

    public function test_emb_pg_02_wrong_dimension_reject(): void
    {
        $this->createChunks(2, false);

        $this->fake->withWrongDimension(100);

        $service = $this->getService();
        $document = $this->getDocument();

        $result = $service->materialize($document);

        $this->assertEquals(0, $result['chunks_processed']);

        $nullCount = DB::table('knowledge_chunks')
            ->where('document_id', $this->docId)
            ->whereNull('embedding')
            ->count();

        $this->assertEquals(2, $nullCount);
    }

    public function test_emb_pg_03_embedding_null_selected(): void
    {
        $this->createChunks(3, false);

        $pending = DB::table('knowledge_chunks')
            ->where('document_id', $this->docId)
            ->whereNull('embedding')
            ->count();

        $this->assertEquals(3, $pending);
    }

    public function test_emb_pg_04_cas_where_null(): void
    {
        $chunkIds = $this->createChunks(2, false);

        $service = $this->getService();
        $document = $this->getDocument();

        $service->materialize($document);

        $fake2 = new FakeEmbeddingProvider;
        app()->instance(EmbeddingProviderInterface::class, $fake2);

        $service2 = $this->getService();
        $result = $service2->materialize($document);

        $this->assertEquals(0, $result['chunks_processed']);
        $this->assertEquals(0, $fake2->callCount());
    }

    public function test_emb_pg_05_transaction_rollback_batch(): void
    {
        $this->createChunks(3, false);

        $callCount = 0;
        $this->fake->onCall(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('Simulated DB failure');
            }
        });

        $service = $this->getService();
        $document = $this->getDocument();

        $result = $service->materialize($document);

        $nullCount = DB::table('knowledge_chunks')
            ->where('document_id', $this->docId)
            ->whereNull('embedding')
            ->count();

        $this->assertEquals(3, $nullCount);
    }

    public function test_emb_pg_06_multi_batch_persist(): void
    {
        $this->createChunks(5, false);

        config(['ai.embedding.providers.openai.max_batch_size' => 2]);

        $service = $this->getService();
        $document = $this->getDocument();

        $result = $service->materialize($document);

        $this->assertEquals(5, $result['chunks_processed']);
        $this->assertEquals(3, $result['batches']);

        $embeddedCount = DB::table('knowledge_chunks')
            ->where('document_id', $this->docId)
            ->whereNotNull('embedding')
            ->count();

        $this->assertEquals(5, $embeddedCount);
    }

    public function test_emb_pg_07_hnsw_index_preserved(): void
    {
        $this->createChunks(2, false);

        $service = $this->getService();
        $document = $this->getDocument();

        $service->materialize($document);

        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'knowledge_chunks' AND indexname = 'knowledge_chunks_embedding_idx'"
        );

        $this->assertNotEmpty($indexes);
    }

    public function test_emb_pg_08_vector_cosine_ops_preserved(): void
    {
        $indexes = DB::select(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'knowledge_chunks' AND indexname = 'knowledge_chunks_embedding_idx'"
        );

        $this->assertNotEmpty($indexes);
        $this->assertStringContainsString('vector_cosine_ops', $indexes[0]->indexdef);
    }

    public function test_emb_pg_09_tenant_isolation(): void
    {
        $tenantB = $this->createTestTenant('Tenant B');
        $kbB = (string) Str::uuid();
        DB::table('knowledge_bases')->insert([
            'id' => $kbB,
            'tenant_id' => $tenantB,
            'name' => 'KB B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $docB = (string) Str::uuid();
        DB::table('knowledge_documents')->insert([
            'id' => $docB,
            'tenant_id' => $tenantB,
            'knowledge_base_id' => $kbB,
            'original_filename' => 'b.txt',
            'storage_disk' => 'minio',
            'storage_path' => 'b.txt',
            'mime_type' => 'text/plain',
            'file_size' => 50,
            'file_hash' => bin2hex(random_bytes(32)),
            'status' => 'ready',
            'chunk_count' => 1,
            'total_tokens' => 100,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('knowledge_chunks')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantB,
            'document_id' => $docB,
            'content' => 'Tenant B chunk',
            'token_count' => 100,
            'chunk_index' => 0,
            'embedding' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createChunks(2, false);

        $service = $this->getService();
        $document = $this->getDocument();

        $service->materialize($document);

        $chunksB = DB::table('knowledge_chunks')
            ->where('tenant_id', $tenantB)
            ->whereNull('embedding')
            ->count();

        $this->assertEquals(1, $chunksB);

        $chunksA = DB::table('knowledge_chunks')
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('embedding')
            ->count();

        $this->assertEquals(2, $chunksA);
    }

    public function test_emb_pg_10_deleted_document_exclusion(): void
    {
        $this->createChunks(2, false);

        DB::table('knowledge_documents')
            ->where('id', $this->docId)
            ->update(['deleted_at' => now()]);

        $service = $this->getService();
        $document = $this->getDocument();

        $result = $service->materialize($document);

        $this->assertEquals(0, $result['chunks_processed']);
        $this->assertEquals(0, $this->fake->callCount());
    }
}
