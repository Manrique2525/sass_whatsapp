<?php

declare(strict_types=1);

use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function integrity_kb_url(Tenant $tenant, string $knowledgeBaseId, ?string $documentId = null): string
{
    $url = "/api/v1/tenants/{$tenant->id}/knowledge-bases/{$knowledgeBaseId}/documents";

    return $documentId === null ? $url : "{$url}/{$documentId}";
}

function integrity_document(Tenant $tenant, KnowledgeBase $knowledgeBase, string $filename = 'integrity.txt'): KnowledgeDocument
{
    return TenantContext::withId($tenant->id, fn (): KnowledgeDocument => KnowledgeDocument::query()->create([
        'knowledge_base_id' => $knowledgeBase->id,
        'original_filename' => $filename,
        'storage_disk' => 'minio',
        'storage_path' => "knowledge/tenant/{$tenant->id}/knowledge-bases/{$knowledgeBase->id}/documents/source.txt",
        'mime_type' => 'text/plain',
        'file_size' => 32,
        'file_hash' => hash('sha256', $filename),
        'status' => 'ready',
        'chunk_count' => 2,
        'total_tokens' => 8,
        'processed_at' => now(),
    ]));
}

function integrity_kb(Tenant $tenant): KnowledgeBase
{
    return TenantContext::withId($tenant->id, fn (): KnowledgeBase => KnowledgeBase::query()->create([
        'name' => 'Integrity KB '.substr((string) str()->uuid(), 0, 8),
    ]));
}

function integrity_chunk(KnowledgeDocument $document, int $index): KnowledgeChunk
{
    return TenantContext::withId($document->tenant_id, function () use ($document, $index): KnowledgeChunk {
        $chunk = new KnowledgeChunk;
        $chunk->forceFill([
            'tenant_id' => $document->tenant_id,
            'document_id' => $document->id,
            'content' => "Contenido de prueba {$index}",
            'token_count' => 4,
            'chunk_index' => $index,
            'metadata' => null,
        ])->save();

        return $chunk;
    });
}

test('document routes are scoped to their knowledge base', function (): void {
    Storage::fake('minio');

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $knowledgeBaseA = integrity_kb($tenant);
    $knowledgeBaseB = integrity_kb($tenant);
    $document = integrity_document($tenant, $knowledgeBaseA);
    integrity_chunk($document, 0);
    Storage::disk('minio')->put($document->storage_path, 'private source');

    $this->actingAs($owner)
        ->getJson(integrity_kb_url($tenant, $knowledgeBaseB->id, $document->id))
        ->assertNotFound();

    $this->actingAs($owner)
        ->deleteJson(integrity_kb_url($tenant, $knowledgeBaseB->id, $document->id))
        ->assertNotFound();

    expect($document->fresh()->deleted_at)->toBeNull();
    expect(Storage::disk('minio')->exists($document->storage_path))->toBeTrue();
    expect(KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $document->id)->count())->toBe(1);
});

test('document routes reject a document from a foreign tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    $knowledgeBaseA = integrity_kb($tenantA);
    $knowledgeBaseB = integrity_kb($tenantB);
    $documentB = integrity_document($tenantB, $knowledgeBaseB, 'foreign.txt');

    $this->actingAs($ownerA)
        ->getJson(integrity_kb_url($tenantA, $knowledgeBaseA->id, $documentB->id))
        ->assertNotFound();
});

test('deleting a document removes its source and derived chunks', function (): void {
    Storage::fake('minio');

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');
    $knowledgeBase = integrity_kb($tenant);
    $document = integrity_document($tenant, $knowledgeBase);
    integrity_chunk($document, 0);
    integrity_chunk($document, 1);
    Storage::disk('minio')->put($document->storage_path, 'private source');

    expect(Storage::disk('minio')->exists($document->storage_path))->toBeTrue();
    expect(KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $document->id)->count())->toBe(2);

    $this->actingAs($owner)
        ->deleteJson(integrity_kb_url($tenant, $knowledgeBase->id, $document->id))
        ->assertOk();

    expect($document->fresh()->deleted_at)->not->toBeNull();
    expect(Storage::disk('minio')->exists($document->storage_path))->toBeFalse();
    expect(KnowledgeChunk::query()->withoutTenantScope()->where('document_id', $document->id)->count())->toBe(0);
});
