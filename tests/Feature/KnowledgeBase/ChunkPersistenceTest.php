<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Services\ChunkPersistenceService;
use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\KnowledgeBase\ValueObjects\TextChunk;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FASE 17 U2.3 — ChunkPersistenceService Integration Tests (PG)
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create();

    make_tenant_member($this->user, $this->tenant, 'owner');

    TenantContext::setId($this->tenant->id);

    $this->document = KnowledgeDocument::factory()
        ->for($this->tenant)
        ->create([
            'status' => KnowledgeDocumentStatus::Uploaded,
        ]);

    $this->service = new ChunkPersistenceService;
});

afterEach(function (): void {
    TenantContext::clear();
});

test('replaceChunks inserts chunks for document', function (): void {
    $chunks = [
        new TextChunk('First chunk content here', 0, 7, []),
        new TextChunk('Second chunk content here', 1, 7, []),
    ];

    $count = $this->service->replaceChunks($this->document, $chunks);

    expect($count)->toBe(2);

    $stored = KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)
        ->get();

    expect($stored)->toHaveCount(2);
    expect($stored[0]->content)->toBe('First chunk content here');
    expect($stored[0]->chunk_index)->toBe(0);
    expect($stored[0]->token_count)->toBe(7);
});

test('replaceChunks sets tenant_id server-side', function (): void {
    $chunks = [
        new TextChunk('Test content', 0, 3, []),
    ];

    $this->service->replaceChunks($this->document, $chunks);

    $stored = KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)
        ->first();

    expect($stored->tenant_id)->toBe($this->tenant->id);
});

test('replaceChunks deletes existing chunks before inserting', function (): void {
    KnowledgeChunk::withoutTenantScope()->insert([
        [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'document_id' => $this->document->id,
            'content' => 'Old chunk',
            'token_count' => 2,
            'chunk_index' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect(KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)->count())->toBe(1);

    $newChunks = [
        new TextChunk('New chunk 1', 0, 3, []),
        new TextChunk('New chunk 2', 1, 3, []),
    ];

    $this->service->replaceChunks($this->document, $newChunks);

    $stored = KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)
        ->get();

    expect($stored)->toHaveCount(2);
    expect($stored->pluck('content')->toArray())->not->toContain('Old chunk');
});

test('replaceChunks with empty array deletes all chunks', function (): void {
    KnowledgeChunk::withoutTenantScope()->insert([
        [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'document_id' => $this->document->id,
            'content' => 'To be deleted',
            'token_count' => 2,
            'chunk_index' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $count = $this->service->replaceChunks($this->document, []);

    expect($count)->toBe(0);
    expect(KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)->count())->toBe(0);
});

test('replaceChunks preserves chunk_index order', function (): void {
    $chunks = [
        new TextChunk('Zero', 0, 1, []),
        new TextChunk('One', 1, 1, []),
        new TextChunk('Two', 2, 1, []),
    ];

    $this->service->replaceChunks($this->document, $chunks);

    $stored = KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)
        ->orderBy('chunk_index')
        ->get();

    expect($stored[0]->chunk_index)->toBe(0);
    expect($stored[1]->chunk_index)->toBe(1);
    expect($stored[2]->chunk_index)->toBe(2);
});

test('replaceChunks stores metadata when provided', function (): void {
    $chunks = [
        new TextChunk('Content', 0, 1, ['page' => 1, 'section' => 'intro']),
    ];

    $this->service->replaceChunks($this->document, $chunks);

    $stored = KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)
        ->first();

    expect($stored->metadata)->toBe(['page' => 1, 'section' => 'intro']);
});

test('replaceChunks sets metadata null when empty', function (): void {
    $chunks = [
        new TextChunk('Content', 0, 1, []),
    ];

    $this->service->replaceChunks($this->document, $chunks);

    $stored = KnowledgeChunk::withoutTenantScope()
        ->where('document_id', $this->document->id)
        ->first();

    expect($stored->metadata)->toBeNull();
});
