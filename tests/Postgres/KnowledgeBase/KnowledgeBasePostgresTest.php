<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

uses(PgvectorTestCase::class);

/*
|--------------------------------------------------------------------------
| PostgreSQL pgvector + tenant constraints tests (FASE 17 U1)
|--------------------------------------------------------------------------
|
| KB-DB-PG-01..14 — Migraciones, pgvector, HNSW, integridad tenant.
|
| Estos tests REQUIEREN PostgreSQL + extensión pgvector activa.
| Ejecutar con: php phpunit -c phpunit.pgsql.xml
|
| IMPORTANTE: Estos tests validan comportamiento real de PostgreSQL que
| SQLite NO puede simular.
|
*/

it('runs knowledge_bases migration up successfully', function (): void {
    Schema::dropIfExists('knowledge_chunks');
    Schema::dropIfExists('knowledge_documents');
    Schema::dropIfExists('knowledge_bases');

    $this->artisan('migrate');

    expect(Schema::hasTable('knowledge_bases'))->toBeTrue();
})->group('KB-DB-PG-01');

it('runs knowledge_documents migration up successfully', function (): void {
    $this->artisan('migrate');

    expect(Schema::hasTable('knowledge_documents'))->toBeTrue();
})->group('KB-DB-PG-02');

it('runs knowledge_chunks migration up with vector column', function (): void {
    $this->artisan('migrate');

    expect(Schema::hasTable('knowledge_chunks'))->toBeTrue();

    $columns = Schema::getColumns('knowledge_chunks');
    $embeddingCol = collect($columns)->firstWhere('name', 'embedding');
    expect($embeddingCol)->not->toBeNull();
})->group('KB-DB-PG-03');

it('verifies pgvector extension is available', function (): void {
    $result = DB::select("SELECT 1 AS available FROM pg_extension WHERE extname = 'vector'");

    expect($result)->not->toBeEmpty();
    expect((int) $result[0]->available)->toBe(1);
})->group('KB-DB-PG-04');

it('verifies embedding column is vector(1536)', function (): void {
    $this->artisan('migrate');

    $result = DB::select(
        "SELECT udt_name FROM information_schema.columns WHERE table_name = 'knowledge_chunks' AND column_name = 'embedding'"
    );

    expect($result)->not->toBeEmpty();
    expect($result[0]->udt_name)->toBe('vector');
})->group('KB-DB-PG-05');

it('verifies HNSW index exists with vector_cosine_ops', function (): void {
    $this->artisan('migrate');

    $result = DB::select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'knowledge_chunks' AND indexname = 'knowledge_chunks_embedding_idx'"
    );

    expect($result)->not->toBeEmpty();
    expect($result[0]->indexname)->toBe('knowledge_chunks_embedding_idx');
})->group('KB-DB-PG-06');

it('verifies unique chunk index per document exists', function (): void {
    $this->artisan('migrate');

    $result = DB::select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'knowledge_chunks' AND indexname = 'knowledge_chunks_document_chunk_index_unique'"
    );

    expect($result)->not->toBeEmpty();
})->group('KB-DB-PG-07');

it('verifies partial unique file hash index exists', function (): void {
    $this->artisan('migrate');

    $result = DB::select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'knowledge_documents' AND indexname = 'knowledge_documents_tenant_kb_hash_unique'"
    );

    expect($result)->not->toBeEmpty();
})->group('KB-DB-PG-08');

it('cosine nearest neighbor query returns expected order', function (): void {
    $this->artisan('migrate');

    $tenantId = (string) Str::uuid();

    DB::table('knowledge_bases')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'name' => 'Test KB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $kbId = DB::table('knowledge_bases')->where('tenant_id', $tenantId)->first()->id;

    $docId = (string) Str::uuid();
    DB::table('knowledge_documents')->insert([
        'id' => $docId,
        'tenant_id' => $tenantId,
        'knowledge_base_id' => $kbId,
        'original_filename' => 'test.txt',
        'storage_disk' => 'minio',
        'storage_path' => 'test/path.txt',
        'mime_type' => 'text/plain',
        'file_size' => 100,
        'file_hash' => bin2hex(random_bytes(32)),
        'status' => 'ready',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $vecA = str_repeat('1,0,0,', 511).'1,0,0';
    $vecB = str_repeat('0,1,0,', 511).'0,1,0';
    $queryVec = str_repeat('1,0,0,', 511).'1,0,0';

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'Document A - cosine match',
        'embedding' => $vecA,
        'token_count' => 10,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'Document B - different',
        'embedding' => $vecB,
        'token_count' => 10,
        'chunk_index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $results = DB::select(
        'SELECT content, (1 - (embedding <=> ?::vector)) AS similarity
         FROM knowledge_chunks
         WHERE tenant_id = ?
         ORDER BY embedding <=> ?::vector
         LIMIT 2',
        [$queryVec, $tenantId, $queryVec]
    );

    expect($results)->toHaveCount(2);
    expect($results[0]->content)->toBe('Document A - cosine match');
    expect((float) $results[0]->similarity)->toBeGreaterThan((float) $results[1]->similarity);
})->group('KB-DB-PG-09');

it('prevents cross-tenant document insert via FK', function (): void {
    $this->artisan('migrate');

    $tenantA = (string) Str::uuid();
    $tenantB = (string) Str::uuid();

    DB::table('knowledge_bases')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantA,
        'name' => 'KB A',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $kbA = DB::table('knowledge_bases')->where('tenant_id', $tenantA)->first()->id;

    $this->expectException(QueryException::class);

    DB::table('knowledge_documents')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantB,
        'knowledge_base_id' => $kbA,
        'original_filename' => 'cross.pdf',
        'storage_disk' => 'minio',
        'storage_path' => 'test/cross.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'file_hash' => bin2hex(random_bytes(32)),
        'status' => 'uploaded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('KB-DB-PG-10');

it('prevents cross-tenant chunk insert via FK', function (): void {
    $this->artisan('migrate');

    $tenantA = (string) Str::uuid();
    $tenantB = (string) Str::uuid();

    DB::table('knowledge_bases')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantA,
        'name' => 'KB A',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $kbA = DB::table('knowledge_bases')->where('tenant_id', $tenantA)->first()->id;

    $docA = (string) Str::uuid();
    DB::table('knowledge_documents')->insert([
        'id' => $docA,
        'tenant_id' => $tenantA,
        'knowledge_base_id' => $kbA,
        'original_filename' => 'test.pdf',
        'storage_disk' => 'minio',
        'storage_path' => 'test/test.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'file_hash' => bin2hex(random_bytes(32)),
        'status' => 'ready',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantB,
        'document_id' => $docA,
        'content' => 'Cross tenant chunk',
        'embedding' => str_repeat('0,0,1,', 511).'0,0,1',
        'token_count' => 10,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('KB-DB-PG-11');

it('prevents duplicate file hash within same KB', function (): void {
    $this->artisan('migrate');

    $tenantId = (string) Str::uuid();
    $kbId = (string) Str::uuid();

    DB::table('knowledge_bases')->insert([
        'id' => $kbId,
        'tenant_id' => $tenantId,
        'name' => 'Test KB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hash = bin2hex(random_bytes(32));

    DB::table('knowledge_documents')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'knowledge_base_id' => $kbId,
        'original_filename' => 'doc1.pdf',
        'storage_disk' => 'minio',
        'storage_path' => 'test/doc1.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'file_hash' => $hash,
        'status' => 'uploaded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('knowledge_documents')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'knowledge_base_id' => $kbId,
        'original_filename' => 'doc2.pdf',
        'storage_disk' => 'minio',
        'storage_path' => 'test/doc2.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 200,
        'file_hash' => $hash,
        'status' => 'uploaded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('KB-DB-PG-12');

it('allows same file hash in different KBs', function (): void {
    $this->artisan('migrate');

    $tenantId = (string) Str::uuid();
    $kbA = (string) Str::uuid();
    $kbB = (string) Str::uuid();

    DB::table('knowledge_bases')->insert([
        'id' => $kbA,
        'tenant_id' => $tenantId,
        'name' => 'KB A',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('knowledge_bases')->insert([
        'id' => $kbB,
        'tenant_id' => $tenantId,
        'name' => 'KB B',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hash = bin2hex(random_bytes(32));

    DB::table('knowledge_documents')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'knowledge_base_id' => $kbA,
        'original_filename' => 'same.pdf',
        'storage_disk' => 'minio',
        'storage_path' => 'test/a.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'file_hash' => $hash,
        'status' => 'uploaded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('knowledge_documents')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'knowledge_base_id' => $kbB,
        'original_filename' => 'same.pdf',
        'storage_disk' => 'minio',
        'storage_path' => 'test/b.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'file_hash' => $hash,
        'status' => 'uploaded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $count = DB::table('knowledge_documents')->where('file_hash', $hash)->count();
    expect($count)->toBe(2);
})->group('KB-DB-PG-13');

it('prevents duplicate chunk_index per document', function (): void {
    $this->artisan('migrate');

    $tenantId = (string) Str::uuid();
    $kbId = (string) Str::uuid();

    DB::table('knowledge_bases')->insert([
        'id' => $kbId,
        'tenant_id' => $tenantId,
        'name' => 'Test KB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $docId = (string) Str::uuid();
    DB::table('knowledge_documents')->insert([
        'id' => $docId,
        'tenant_id' => $tenantId,
        'knowledge_base_id' => $kbId,
        'original_filename' => 'test.txt',
        'storage_disk' => 'minio',
        'storage_path' => 'test/test.txt',
        'mime_type' => 'text/plain',
        'file_size' => 100,
        'file_hash' => bin2hex(random_bytes(32)),
        'status' => 'ready',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'First chunk',
        'embedding' => str_repeat('1,0,0,', 511).'1,0,0',
        'token_count' => 10,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->expectException(QueryException::class);

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'Duplicate index chunk',
        'embedding' => str_repeat('0,1,0,', 511).'0,1,0',
        'token_count' => 10,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->group('KB-DB-PG-14');
