<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FASE 17 P0 — Embedding Nullability Migration Tests (PostgreSQL)
|--------------------------------------------------------------------------
|
| EMB-NULL-PG-01..07 — Validates the corrective migration that makes
| knowledge_chunks.embedding nullable for U2.3 (chunks before embeddings).
|
| These tests REQUIRE PostgreSQL + pgvector.
| Run with: php artisan test --group=EMB-NULL-PG
|
*/

function embNullCreateTestTenant(string $name = 'Test Tenant'): string
{
    $tenantId = (string) Str::uuid();
    $slug = 'test-'.strtolower(Str::random(8));
    DB::table('tenants')->insert([
        'id' => $tenantId,
        'name' => $name,
        'slug' => $slug,
        'status' => 'active',
        'timezone' => 'UTC',
        'locale' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $tenantId;
}

function embNullCreateTestDocument(string $tenantId): string
{
    $docId = (string) Str::uuid();
    $kbId = (string) Str::uuid();

    DB::table('knowledge_bases')->insert([
        'id' => $kbId,
        'tenant_id' => $tenantId,
        'name' => 'Test KB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('knowledge_documents')->insert([
        'id' => $docId,
        'tenant_id' => $tenantId,
        'knowledge_base_id' => $kbId,
        'filename' => 'test.txt',
        'storage_path' => 'test/path.txt',
        'file_size' => 100,
        'mime_type' => 'text/plain',
        'file_hash' => hash('sha256', 'test'),
        'status' => 'uploaded',
        'chunk_count' => 0,
        'total_tokens' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $docId;
}

it('EMB-NULL-PG-01: after migration embedding column is nullable', function (): void {
    $this->artisan('migrate:fresh');

    $result = DB::select(
        "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'knowledge_chunks' AND column_name = 'embedding'"
    );

    expect($result)->not->toBeEmpty();
    expect($result[0]->is_nullable)->toBe('YES');
})->group('EMB-NULL-PG');

it('EMB-NULL-PG-02: insert knowledge_chunk with embedding NULL succeeds', function (): void {
    $this->artisan('migrate:fresh');

    $tenantId = embNullCreateTestTenant();
    $docId = embNullCreateTestDocument($tenantId);

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'Test chunk without embedding',
        'token_count' => 4,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $chunk = DB::table('knowledge_chunks')->where('document_id', $docId)->first();
    expect($chunk)->not->toBeNull();
    expect($chunk->embedding)->toBeNull();
    expect($chunk->content)->toBe('Test chunk without embedding');
})->group('EMB-NULL-PG');

it('EMB-NULL-PG-03: insert knowledge_chunk with valid vector(1536) succeeds', function (): void {
    $this->artisan('migrate:fresh');

    $tenantId = embNullCreateTestTenant();
    $docId = embNullCreateTestDocument($tenantId);

    $vector = '['.implode(',', array_fill(0, 1536, '0.1')).']';

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'Test chunk with embedding',
        'embedding' => $vector,
        'token_count' => 4,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $chunk = DB::table('knowledge_chunks')->where('document_id', $docId)->first();
    expect($chunk)->not->toBeNull();
    expect($chunk->embedding)->not->toBeNull();
})->group('EMB-NULL-PG');

it('EMB-NULL-PG-04: HNSW index still exists after ALTER', function (): void {
    $this->artisan('migrate:fresh');

    $result = DB::select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'knowledge_chunks' AND indexname = 'knowledge_chunks_embedding_idx'"
    );

    expect($result)->not->toBeEmpty();
    expect($result[0]->indexname)->toBe('knowledge_chunks_embedding_idx');
})->group('EMB-NULL-PG');

it('EMB-NULL-PG-05: HNSW index uses vector_cosine_ops', function (): void {
    $this->artisan('migrate:fresh');

    $result = DB::select(
        "SELECT indexdef FROM pg_indexes WHERE tablename = 'knowledge_chunks' AND indexname = 'knowledge_chunks_embedding_idx'"
    );

    expect($result)->not->toBeEmpty();
    expect($result[0]->indexdef)->toContain('vector_cosine_ops');
    expect($result[0]->indexdef)->toContain('hnsw');
})->group('EMB-NULL-PG');

it('EMB-NULL-PG-06: UP/DOWN/UP works when no NULLs exist', function (): void {
    $this->artisan('migrate:fresh');

    $tenantId = embNullCreateTestTenant();
    $docId = embNullCreateTestDocument($tenantId);

    $vector = '['.implode(',', array_fill(0, 1536, '0.5')).']';
    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'Embedded chunk',
        'embedding' => $vector,
        'token_count' => 2,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_08_19_161000_make_knowledge_chunks_embedding_nullable.php']);

    $afterRollback = DB::select(
        "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'knowledge_chunks' AND column_name = 'embedding'"
    );
    expect($afterRollback[0]->is_nullable)->toBe('NO');

    $this->artisan('migrate', ['--path' => 'database/migrations/2026_08_19_161000_make_knowledge_chunks_embedding_nullable.php']);

    $afterRemigrate = DB::select(
        "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'knowledge_chunks' AND column_name = 'embedding'"
    );
    expect($afterRemigrate[0]->is_nullable)->toBe('YES');
})->group('EMB-NULL-PG');

it('EMB-NULL-PG-07: DOWN with NULL rows throws RuntimeException', function (): void {
    $this->artisan('migrate:fresh');

    $tenantId = embNullCreateTestTenant();
    $docId = embNullCreateTestDocument($tenantId);

    DB::table('knowledge_chunks')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'document_id' => $docId,
        'content' => 'Chunk with null embedding',
        'token_count' => 3,
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_08_19_161000_make_knowledge_chunks_embedding_nullable.php']);
})->throws(RuntimeException::class, 'Cannot revert embedding to NOT NULL')
    ->group('EMB-NULL-PG');
