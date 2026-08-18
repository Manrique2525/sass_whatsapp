<?php

declare(strict_types=1);

use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| KnowledgeBase model tests (SQLite)
|--------------------------------------------------------------------------
|
| KB-DB-01..19 — Model, relationships, enums, factories, cascades.
| Corren en SQLite :memory: (phpunit.xml default).
| No validan pgvector ni HNSW (ver tests/Postgres/KnowledgeBase/).
|
*/

function createKnowledgeContext(): array
{
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $kb = KnowledgeBase::factory()->create();
    $doc = KnowledgeDocument::factory()->create([
        'knowledge_base_id' => $kb->id,
    ]);
    $chunk = KnowledgeChunk::factory()->create([
        'document_id' => $doc->id,
    ]);

    return ['tenant' => $tenant, 'kb' => $kb, 'doc' => $doc, 'chunk' => $chunk];
}

it('creates a knowledge base via factory', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['kb']->id)->toBeString()->not->toBeEmpty();
    expect($ctx['kb']->name)->toBeString()->not->toBeEmpty();
    expect($ctx['kb']->tenant_id)->toBe($ctx['tenant']->id);
    expect($ctx['kb']->created_at)->not->toBeNull();
    expect($ctx['kb']->updated_at)->not->toBeNull();
})->group('KB-DB-01');

it('casts knowledge base attributes correctly', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['kb']->getAttributes())->toHaveKeys(['id', 'tenant_id', 'name', 'description']);
    expect(is_string($ctx['kb']->id))->toBeTrue();
    expect(is_string($ctx['kb']->tenant_id))->toBeTrue();
})->group('KB-DB-02');

it('creates a knowledge document via factory', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['doc']->id)->toBeString()->not->toBeEmpty();
    expect($ctx['doc']->original_filename)->toBeString()->not->toBeEmpty();
    expect($ctx['doc']->storage_disk)->toBe('minio');
    expect($ctx['doc']->storage_path)->toBeString()->not->toBeEmpty();
    expect($ctx['doc']->mime_type)->toBeString()->not->toBeEmpty();
    expect($ctx['doc']->file_size)->toBeInt()->toBeGreaterThan(0);
    expect($ctx['doc']->file_hash)->toBeString()->toHaveLength(64);
    expect($ctx['doc']->status)->toBe(KnowledgeDocumentStatus::Uploaded);
})->group('KB-DB-03');

it('casts knowledge document status as enum', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['doc']->status)->toBeInstanceOf(KnowledgeDocumentStatus::class);
    expect($ctx['doc']->status)->toBe(KnowledgeDocumentStatus::Uploaded);
})->group('KB-DB-04');

it('casts knowledge document timestamps correctly', function (): void {
    $ctx = createKnowledgeContext();
    $doc = KnowledgeDocument::factory()->ready()->create([
        'knowledge_base_id' => $ctx['kb']->id,
    ]);

    expect($doc->processed_at)->not->toBeNull();
    expect($doc->processed_at)->toBeInstanceOf(Carbon::class);
})->group('KB-DB-05');

it('creates a knowledge chunk via factory', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['chunk']->id)->toBeString()->not->toBeEmpty();
    expect($ctx['chunk']->content)->toBeString()->not->toBeEmpty();
    expect($ctx['chunk']->token_count)->toBeInt()->toBeGreaterThan(0);
    expect($ctx['chunk']->chunk_index)->toBeInt()->toBeGreaterThanOrEqual(0);
})->group('KB-DB-06');

it('casts knowledge chunk attributes correctly', function (): void {
    $ctx = createKnowledgeContext();

    $chunk = KnowledgeChunk::factory()->create([
        'document_id' => $ctx['doc']->id,
        'token_count' => 123,
        'chunk_index' => 5,
        'metadata' => ['page' => 1, 'section' => 'intro'],
    ]);

    expect($chunk->token_count)->toBe(123);
    expect($chunk->chunk_index)->toBe(5);
    expect($chunk->metadata)->toBeArray();
    expect($chunk->metadata)->toHaveKeys(['page', 'section']);
})->group('KB-DB-07');

it('knowledge base has many documents', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    $kb = KnowledgeBase::factory()->create();
    KnowledgeDocument::factory()->create(['knowledge_base_id' => $kb->id]);
    KnowledgeDocument::factory()->create(['knowledge_base_id' => $kb->id]);

    expect($kb->documents)->toHaveCount(2);
})->group('KB-DB-08');

it('knowledge document belongs to knowledge base', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['doc']->knowledgeBase)->toBeInstanceOf(KnowledgeBase::class);
    expect($ctx['doc']->knowledgeBase->id)->toBe($ctx['kb']->id);
})->group('KB-DB-09');

it('knowledge document has many chunks', function (): void {
    $ctx = createKnowledgeContext();
    KnowledgeChunk::factory()->create([
        'document_id' => $ctx['doc']->id,
        'chunk_index' => 10,
    ]);
    KnowledgeChunk::factory()->create([
        'document_id' => $ctx['doc']->id,
        'chunk_index' => 11,
    ]);

    expect($ctx['doc']->fresh()->chunks)->toHaveCount(3);
})->group('KB-DB-10');

it('knowledge chunk belongs to document', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['chunk']->document)->toBeInstanceOf(KnowledgeDocument::class);
    expect($ctx['chunk']->document->id)->toBe($ctx['doc']->id);
})->group('KB-DB-11');

it('knowledge base belongs to tenant', function (): void {
    $ctx = createKnowledgeContext();

    expect($ctx['kb']->tenant)->toBeInstanceOf(Tenant::class);
    expect($ctx['kb']->tenant->id)->toBe($ctx['tenant']->id);
})->group('KB-DB-12');

it('knowledge document factory state ready works', function (): void {
    $ctx = createKnowledgeContext();
    $doc = KnowledgeDocument::factory()->ready()->create([
        'knowledge_base_id' => $ctx['kb']->id,
    ]);

    expect($doc->status)->toBe(KnowledgeDocumentStatus::Ready);
    expect($doc->chunk_count)->not->toBeNull();
    expect($doc->chunk_count)->toBeInt()->toBeGreaterThan(0);
    expect($doc->total_tokens)->toBeInt()->toBeGreaterThan(0);
    expect($doc->processed_at)->not->toBeNull();
})->group('KB-DB-13');

it('knowledge document factory state failed works', function (): void {
    $ctx = createKnowledgeContext();
    $doc = KnowledgeDocument::factory()->failed()->create([
        'knowledge_base_id' => $ctx['kb']->id,
    ]);

    expect($doc->status)->toBe(KnowledgeDocumentStatus::Failed);
    expect($doc->error_message)->toBeString()->not->toBeEmpty();
})->group('KB-DB-14');

it('knowledge document status has correct cases', function (): void {
    expect(KnowledgeDocumentStatus::cases())->toHaveCount(4);
    expect(KnowledgeDocumentStatus::Uploaded->value)->toBe('uploaded');
    expect(KnowledgeDocumentStatus::Processing->value)->toBe('processing');
    expect(KnowledgeDocumentStatus::Ready->value)->toBe('ready');
    expect(KnowledgeDocumentStatus::Failed->value)->toBe('failed');
})->group('KB-DB-15');

it('knowledge document status label returns correct values', function (): void {
    expect(KnowledgeDocumentStatus::Uploaded->label())->toBe('Pending processing');
    expect(KnowledgeDocumentStatus::Processing->label())->toBe('Processing');
    expect(KnowledgeDocumentStatus::Ready->label())->toBe('Ready');
    expect(KnowledgeDocumentStatus::Failed->label())->toBe('Failed');
})->group('KB-DB-16');

it('knowledge document status isTerminal returns correct values', function (): void {
    expect(KnowledgeDocumentStatus::Uploaded->isTerminal())->toBeFalse();
    expect(KnowledgeDocumentStatus::Processing->isTerminal())->toBeFalse();
    expect(KnowledgeDocumentStatus::Ready->isTerminal())->toBeTrue();
    expect(KnowledgeDocumentStatus::Failed->isTerminal())->toBeTrue();
})->group('KB-DB-17');

it('soft deleting knowledge base preserves documents', function (): void {
    $ctx = createKnowledgeContext();

    $ctx['kb']->delete();

    expect($ctx['kb']->fresh()->deleted_at)->not->toBeNull();
    expect(KnowledgeDocument::find($ctx['doc']->id))->not->toBeNull();
})->group('KB-DB-18');

it('soft deleting knowledge document preserves chunks', function (): void {
    $ctx = createKnowledgeContext();

    $ctx['doc']->delete();

    expect(KnowledgeChunk::find($ctx['chunk']->id))->not->toBeNull();
})->group('KB-DB-19');
