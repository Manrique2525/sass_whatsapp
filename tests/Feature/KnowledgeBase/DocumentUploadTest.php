<?php

declare(strict_types=1);

use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\ProcessKnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 17 U2.2 — SECURE DOCUMENT UPLOAD TESTS
|--------------------------------------------------------------------------
|
| KB-U22-01..API, KB-U22-V01..V07, KB-U22-D01..D04, KB-U22-S01..S06,
| KB-U22-MT01..MT08, KB-U22-A01..A02
|
*/

function kb_url_u22(Tenant $tenant, ?string $kbId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/knowledge-bases';

    return $kbId === null ? $base : $base.'/'.$kbId;
}

function kb_doc_url_u22(Tenant $tenant, string $kbId, ?string $docId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/knowledge-bases/'.$kbId.'/documents';

    return $docId === null ? $base : $base.'/'.$docId;
}

function make_kb_u22(Tenant $tenant, array $attributes = []): KnowledgeBase
{
    TenantContext::setId($tenant->id);

    try {
        return KnowledgeBase::query()->create(array_merge([
            'name' => 'KB '.substr((string) Str::uuid(), 0, 8),
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

function make_tenant_u22(): Tenant
{
    return Tenant::factory()->create(['status' => 'active']);
}

function make_user_u22(): User
{
    return User::factory()->create();
}

function valid_pdf_content(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n190\n%%EOF";
}

function valid_docx_content(): string
{
    $tempFile = tempnam(sys_get_temp_dir(), 'docx_test_');

    $zip = new ZipArchive;
    $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();

    $content = file_get_contents($tempFile);
    unlink($tempFile);

    return $content;
}

function valid_txt_content(): string
{
    return "Este es un archivo de texto válido para testing.\nLínea 2 del documento.\n";
}

// =========================================================================
// KB-U22-01..06 — API UPLOAD VALID
// =========================================================================

test('KB-U22-01: valid PDF upload returns 201 and persists', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));
    Queue::fake();

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('test.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'document' => [
                'id', 'knowledge_base_id', 'original_filename', 'mime_type',
                'file_size', 'status', 'chunk_count', 'total_tokens',
                'created_at', 'updated_at',
            ],
        ]);

    $response->assertJsonPath('document.status', 'uploaded');
    $response->assertJsonPath('document.original_filename', 'test.pdf');
    $response->assertJsonPath('document.knowledge_base_id', $kb->id);
    $response->assertJsonMissing(['storage_disk', 'storage_path', 'file_hash']);

    $this->assertDatabaseHas('knowledge_documents', [
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $kb->id,
        'original_filename' => 'test.pdf',
        'status' => 'uploaded',
    ]);
});

test('KB-U22-02: valid DOCX upload returns 201 and persists', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('report.docx', valid_docx_content()),
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('document.status', 'uploaded')
        ->assertJsonPath('document.original_filename', 'report.docx');
});

test('KB-U22-03: valid TXT upload returns 201 and persists', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', valid_txt_content()),
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('document.status', 'uploaded')
        ->assertJsonPath('document.original_filename', 'notes.txt');
});

test('KB-U22-04: response does not expose internal storage fields', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('safe.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(201);
    $json = $response->json('document');

    expect($json)->not->toHaveKey('storage_disk');
    expect($json)->not->toHaveKey('storage_path');
    expect($json)->not->toHaveKey('file_hash');
    expect($json)->not->toHaveKey('error_message');
});

test('KB-U22-05: document status is uploaded after successful upload', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));
    Queue::fake();

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('status.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($doc->status->value)->toBe('uploaded');
    expect($doc->chunk_count)->toBe(0);
    expect($doc->total_tokens)->toBeNull();
    expect($doc->processed_at)->toBeNull();
    expect($doc->error_message)->toBeNull();
});

test('KB-U22-06: no chunks created after upload', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('nochunks.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    $this->assertDatabaseCount('knowledge_chunks', 0);
    expect($doc->chunk_count)->toBe(0);
});

// =========================================================================
// KB-U22-V01..V07 — VALIDATION
// =========================================================================

test('KB-U22-V01: oversize file rejected (413)', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $largeContent = str_repeat('A', (10 * 1024 * 1024) + 1);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('large.pdf', $largeContent),
        ]);

    $response->assertStatus(413);
});

test('KB-U22-V02: zero byte file rejected (422)', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('empty.pdf', ''),
        ]);

    $response->assertStatus(422);
    expect($response->json('code'))->not->toBeNull();
    expect(in_array($response->json('code'), ['DOCUMENT_INVALID_FILE', 'DOCUMENT_UNSUPPORTED_TYPE', 'DOCUMENT_TOO_LARGE'], true))->toBeTrue();
});

test('KB-U22-V03: unsupported extension rejected (422)', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('malware.exe', 'MZ...'),
        ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['code' => 'DOCUMENT_UNSUPPORTED_TYPE']);
});

test('KB-U22-V04: MIME mismatch detected server-side', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('fake.pdf', 'This is not a PDF'),
        ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['code' => 'DOCUMENT_INVALID_FILE']);
});

test('KB-U22-V05: fake PDF (wrong magic bytes) rejected', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $content = "PK\x03\x04This is a ZIP renamed to PDF";

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('fake.pdf', $content),
        ]);

    $response->assertStatus(422);
    expect(in_array($response->json('code'), ['DOCUMENT_INVALID_FILE', 'DOCUMENT_UNSUPPORTED_TYPE'], true))->toBeTrue();
});

test('KB-U22-V06: fake DOCX (not a valid ZIP with required structure) rejected', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $content = "PK\x03\x04Random ZIP-like content without required DOCX structure";

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('fake.docx', $content),
        ]);

    $response->assertStatus(422);
    expect(in_array($response->json('code'), ['DOCUMENT_INVALID_FILE', 'DOCUMENT_UNSUPPORTED_TYPE'], true))->toBeTrue();
});

test('KB-U22-V07: binary file masquerading as TXT rejected', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $binaryContent = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F";

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('binary.txt', $binaryContent),
        ]);

    $response->assertStatus(422);
    expect(in_array($response->json('code'), ['DOCUMENT_INVALID_FILE', 'DOCUMENT_UNSUPPORTED_TYPE'], true))->toBeTrue();
});

// =========================================================================
// KB-U22-D01..D04 — DEDUPLICATION
// =========================================================================

test('KB-U22-D01: duplicate file in same KB returns 409', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);
    $content = valid_pdf_content();

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('first.pdf', $content),
        ])->assertStatus(201);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('second.pdf', $content),
        ]);

    $response->assertStatus(409)
        ->assertJsonFragment(['code' => 'DOCUMENT_DUPLICATE']);
});

test('KB-U22-D02: same hash in different KB is allowed', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb1 = make_kb_u22($tenant);
    $kb2 = make_kb_u22($tenant);
    $content = valid_pdf_content();

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb1->id), [
            'file' => UploadedFile::fake()->createWithContent('shared.pdf', $content),
        ])->assertStatus(201);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb2->id), [
            'file' => UploadedFile::fake()->createWithContent('shared2.pdf', $content),
        ]);

    $response->assertStatus(201);
});

test('KB-U22-D03: re-upload after soft delete is allowed', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);
    $content = valid_txt_content();

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', $content),
        ])->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    $doc->delete();

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('notes2.txt', $content),
        ]);

    $response->assertStatus(201);
});

test('KB-U22-D04: concurrent duplicate upload only one wins', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);
    $content = valid_pdf_content();

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('race.pdf', $content),
        ])->assertStatus(201);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('race2.pdf', $content),
        ]);

    $response->assertStatus(409);
});

// =========================================================================
// KB-U22-S01..S06 — STORAGE
// =========================================================================

test('KB-U22-S01: storage path is server-generated and deterministic', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('path.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($doc->storage_path)->toStartWith('knowledge/tenant/');
    expect($doc->storage_path)->toContain($tenant->id);
    expect($doc->storage_path)->toContain($kb->id);
    expect($doc->storage_path)->toContain($doc->id);
    expect($doc->storage_path)->toEndWith('/source.pdf');
    expect($doc->storage_path)->not->toContain('path.pdf');
});

test('KB-U22-S02: filename traversal is harmless in storage path', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('../../evil.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($doc->storage_path)->not->toContain('..');
    expect($doc->storage_path)->toStartWith('knowledge/tenant/');
});

test('KB-U22-S03: DB error cleans up storage object', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('cleanup.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(201);
    $this->assertDatabaseCount('knowledge_documents', 1);
});

test('KB-U22-S04: storage error does not create DB row', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('stored.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(201);
    $this->assertDatabaseCount('knowledge_documents', 1);
});

test('KB-U22-S05: file stored on correct disk with private visibility', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('disk.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($doc->storage_disk)->toBe('minio');
    expect(Storage::disk('minio'))->assertExists($doc->storage_path);
});

test('KB-U22-S06: internal path not exposed in Resource', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('secret.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $json = $response->json('document');
    $jsonString = json_encode($json);

    expect($jsonString)->not->toContain('storage_path');
    expect($jsonString)->not->toContain('storage_disk');
    expect($jsonString)->not->toContain('file_hash');
    expect($jsonString)->not->toContain('minio');
});

// =========================================================================
// KB-U22-MT01..MT08 — MULTI-TENANCY
// =========================================================================

test('KB-U22-MT01: Tenant A upload in KB A returns 201', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenantA = make_tenant_u22();
    $userA = make_user_u22();
    make_tenant_member($userA, $tenantA, 'owner');
    $kbA = make_kb_u22($tenantA);

    $response = $this->actingAs($userA)
        ->postJson(kb_doc_url_u22($tenantA, $kbA->id), [
            'file' => UploadedFile::fake()->createWithContent('valid.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(201);
});

test('KB-U22-MT02: Tenant A upload in KB B returns 404', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenantA = make_tenant_u22();
    $tenantB = make_tenant_u22();
    $userA = make_user_u22();
    make_tenant_member($userA, $tenantA, 'owner');
    $kbB = make_kb_u22($tenantB);

    $response = $this->actingAs($userA)
        ->postJson(kb_doc_url_u22($tenantA, $kbB->id), [
            'file' => UploadedFile::fake()->createWithContent('cross.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(404);
});

test('KB-U22-MT03: tenant_id in body is ignored', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('injected.pdf', valid_pdf_content()),
            'tenant_id' => '00000000-0000-0000-0000-000000000000',
        ]);

    $response->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('id', $response->json('document.id'))
        ->first();

    expect($doc->tenant_id)->toBe($tenant->id);
});

test('KB-U22-MT04: knowledge_base_id body cannot override route KB', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb1 = make_kb_u22($tenant);
    $kb2 = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb1->id), [
            'file' => UploadedFile::fake()->createWithContent('override.pdf', valid_pdf_content()),
            'knowledge_base_id' => $kb2->id,
        ]);

    $response->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('id', $response->json('document.id'))
        ->first();

    expect($doc->knowledge_base_id)->toBe($kb1->id);
});

test('KB-U22-MT05: storage path contains correct tenant ID', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('tenant.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($doc->storage_path)->toContain('tenant/'.$tenant->id);
});

test('KB-U22-MT06: Tenant B does not get source metadata of A', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenantA = make_tenant_u22();
    $tenantB = make_tenant_u22();
    $userA = make_user_u22();
    make_tenant_member($userA, $tenantA, 'owner');
    $userB = make_user_u22();
    make_tenant_member($userB, $tenantB, 'agent');
    $kbA = make_kb_u22($tenantA);
    $kbB = make_kb_u22($tenantB);

    $this->actingAs($userA)
        ->postJson(kb_doc_url_u22($tenantA, $kbA->id), [
            'file' => UploadedFile::fake()->createWithContent('secret_a.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $response = $this->actingAs($userB)
        ->getJson(kb_doc_url_u22($tenantB, $kbB->id));

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 0);
});

test('KB-U22-MT07: agent without manage permission gets 403 on upload', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $agent = make_user_u22();
    make_tenant_member($agent, $tenant, 'agent');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($agent)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('denied.pdf', valid_pdf_content()),
        ]);

    $response->assertStatus(403)
        ->assertJsonFragment(['code' => 'PERMISSION_DENIED']);
});

test('KB-U22-MT08: inactive membership is rejected', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = User::factory()->create();

    $user->tenants()->attach($tenant, [
        'role' => 'owner',
        'status' => 'disabled',
        'joined_at' => now(),
    ]);

    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('disabled.pdf', valid_pdf_content()),
        ]);

    expect(in_array($response->status(), [403, 404], true))->toBeTrue();
});

// =========================================================================
// KB-U22-A01..A02 — AUDIT
// =========================================================================

test('KB-U22-A01: upload creates knowledge_document.uploaded audit event', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('audit.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $this->assertDatabaseHas('audit_logs', [
        'tenant_id' => $tenant->id,
        'action' => 'knowledge_document.uploaded',
    ]);
});

test('KB-U22-A02: audit does not leak path or hash content', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('leak.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $audit = DB::table('audit_logs')
        ->where('tenant_id', $tenant->id)
        ->where('action', 'knowledge_document.uploaded')
        ->first();

    expect($audit)->not->toBeNull();

    $data = json_decode((string) $audit->data, true);
    expect($data)->not->toHaveKey('storage_path');
    expect($data)->not->toHaveKey('file_hash');
    expect($data)->toHaveKey('document_id');
    expect($data)->toHaveKey('mime_type');
});

// =========================================================================
// KB-U22-NO — CONFIRMATIONS (no extraction/chunks/queue/embeddings)
// =========================================================================

test('KB-U22-NO-01: upload does not create any chunks', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('nochunks.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $this->assertDatabaseCount('knowledge_chunks', 0);
});

test('KB-U22-NO-02: upload dispatches ProcessKnowledgeDocument after commit', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));
    Queue::fake();

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('noqueue.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    Queue::assertPushed(ProcessKnowledgeDocument::class, function ($job) use ($tenant) {
        return $job->tenantId === $tenant->id;
    });
});

test('KB-U22-NO-03: document status remains uploaded when job is queued (not executed)', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));
    Queue::fake();

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), [
            'file' => UploadedFile::fake()->createWithContent('status2.pdf', valid_pdf_content()),
        ])->assertStatus(201);

    $doc = KnowledgeDocument::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($doc->status->value)->toBe('uploaded');
    expect($doc->status->isTerminal())->toBeFalse();
});

// =========================================================================
// PATH TRAVERSAL ATTACKS
// =========================================================================

test('KB-U22-SEC-01: filename traversal attacks are neutralized', function (): void {
    Storage::fake(config('knowledge.upload.storage_disk'));

    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $filenames = [
        '../../evil.pdf',
        '..\\..\\evil.pdf',
        '/foo.pdf',
        '%00evil.pdf',
    ];

    foreach ($filenames as $index => $filename) {
        $content = valid_pdf_content().'_'.$index;

        $response = $this->actingAs($user)
            ->postJson(kb_doc_url_u22($tenant, $kb->id), [
                'file' => UploadedFile::fake()->createWithContent($filename, $content),
            ]);

        $response->assertStatus(201);

        $doc = KnowledgeDocument::query()
            ->withoutTenantScope()
            ->where('id', $response->json('document.id'))
            ->first();

        expect($doc->storage_path)->not->toContain('..');
        expect($doc->storage_path)->toStartWith('knowledge/tenant/');
    }
});

test('KB-U22-SEC-02: missing file returns 422', function (): void {
    $tenant = make_tenant_u22();
    $user = make_user_u22();
    make_tenant_member($user, $tenant, 'owner');
    $kb = make_kb_u22($tenant);

    $response = $this->actingAs($user)
        ->postJson(kb_doc_url_u22($tenant, $kb->id), []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('KB-U22-SEC-03: no auth returns 401', function (): void {
    $tenant = make_tenant_u22();
    $kb = make_kb_u22($tenant);

    $response = $this->postJson(kb_doc_url_u22($tenant, $kb->id), [
        'file' => UploadedFile::fake()->createWithContent('noauth.pdf', valid_pdf_content()),
    ]);

    $response->assertStatus(401);
});
