<?php

declare(strict_types=1);

use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('minio');
});

/*
|--------------------------------------------------------------------------
| FASE 17 U2.1 -- KNOWLEDGE BASE MANAGEMENT API
|--------------------------------------------------------------------------
*/

function kb_url(Tenant $tenant, ?string $kbId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/knowledge-bases';

    return $kbId === null ? $base : $base.'/'.$kbId;
}

function kb_doc_url(Tenant $tenant, string $kbId, ?string $docId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/knowledge-bases/'.$kbId.'/documents';

    return $docId === null ? $base : $base.'/'.$docId;
}

function make_kb(Tenant $tenant, array $attributes = []): KnowledgeBase
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

function make_kb_document(Tenant $tenant, KnowledgeBase $kb, array $attributes = []): KnowledgeDocument
{
    TenantContext::setId($tenant->id);

    try {
        $document = KnowledgeDocument::query()->create(array_merge([
            'knowledge_base_id' => $kb->id,
            'original_filename' => 'doc-'.substr((string) Str::uuid(), 0, 8).'.txt',
            'storage_disk' => 'minio',
            'storage_path' => 'tenant/'.$tenant->id.'/kb/'.$kb->id.'/doc.txt',
            'mime_type' => 'text/plain',
            'file_size' => 1024,
            'file_hash' => hash('sha256', (string) Str::uuid()),
            'status' => 'uploaded',
        ], $attributes));

        Storage::disk('minio')->put($document->storage_path, 'test document source');

        return $document;
    } finally {
        TenantContext::clear();
    }
}

// =========================================================================
// KB-U21-01..N -- API CRUD
// =========================================================================

test('KB-U21-01: crear una knowledge base devuelve 201 y la persiste', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(kb_url($tenant), [
            'name' => 'FAQ Principal',
            'description' => 'Preguntas frecuentes del negocio',
        ])
        ->assertStatus(201)
        ->assertJsonPath('knowledge_base.name', 'FAQ Principal')
        ->assertJsonPath('knowledge_base.description', 'Preguntas frecuentes del negocio');

    $this->assertDatabaseHas('knowledge_bases', [
        'tenant_id' => $tenant->id,
        'name' => 'FAQ Principal',
    ]);
});

test('KB-U21-02: crear valida name requerido y max length', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(kb_url($tenant), ['description' => 'Sin nombre'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->actingAs($owner)
        ->postJson(kb_url($tenant), ['name' => str_repeat('A', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('KB-U21-03: crear con nombre duplicado devuelve 409 KB_DUPLICATE', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_kb($tenant, ['name' => 'FAQ Duplicada']);

    $this->actingAs($owner)
        ->postJson(kb_url($tenant), ['name' => 'FAQ Duplicada'])
        ->assertStatus(409)
        ->assertJson(['code' => 'KB_DUPLICATE']);
});

test('KB-U21-04: tras soft delete se puede re-crear el mismo nombre', function (): void {
    // SQLite unique index is NOT partial (no WHERE deleted_at IS NULL), so
    // soft-deleted rows block re-creation. This behavior is PG-specific.
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Partial unique index requires PostgreSQL.');
    }

    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant, ['name' => 'FAQ']);

    $this->actingAs($owner)
        ->deleteJson(kb_url($tenant, $kb->id))
        ->assertOk();

    $this->actingAs($owner)
        ->postJson(kb_url($tenant), ['name' => 'FAQ'])
        ->assertStatus(201);
});

test('KB-U21-05: index pagina y filtra por search', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_kb($tenant, ['name' => 'FAQ Productos']);
    make_kb($tenant, ['name' => 'FAQ Envios']);
    make_kb($tenant, ['name' => 'Guia Tecnica']);

    $this->actingAs($owner)
        ->getJson(kb_url($tenant))
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(3, 'knowledge_bases');

    $this->actingAs($owner)
        ->getJson(kb_url($tenant).'?search=FAQ')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($owner)
        ->getJson(kb_url($tenant).'?search=Guia')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('knowledge_bases.0.name', 'Guia Tecnica');
});

test('KB-U21-06: show devuelve la knowledge base solicitada', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant, ['name' => 'FAQ Show']);

    $this->actingAs($owner)
        ->getJson(kb_url($tenant, $kb->id))
        ->assertOk()
        ->assertJsonPath('knowledge_base.id', $kb->id)
        ->assertJsonPath('knowledge_base.name', 'FAQ Show');
});

test('KB-U21-07: update parcial actualiza name y description', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant, ['name' => 'FAQ Original', 'description' => 'Original']);

    $this->actingAs($owner)
        ->patchJson(kb_url($tenant, $kb->id), [
            'name' => 'FAQ Actualizada',
            'description' => 'Actualizada',
        ])
        ->assertOk()
        ->assertJsonPath('knowledge_base.name', 'FAQ Actualizada')
        ->assertJsonPath('knowledge_base.description', 'Actualizada');
});

test('KB-U21-08: update sin cambios retorna 200 sin modificar', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant, ['name' => 'Sin Cambios']);

    $this->actingAs($owner)
        ->patchJson(kb_url($tenant, $kb->id), ['name' => 'Sin Cambios'])
        ->assertOk();
});

test('KB-U21-09: update a nombre duplicado devuelve 409', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb1 = make_kb($tenant, ['name' => 'FAQ A']);
    make_kb($tenant, ['name' => 'FAQ B']);

    $this->actingAs($owner)
        ->patchJson(kb_url($tenant, $kb1->id), ['name' => 'FAQ B'])
        ->assertStatus(409)
        ->assertJson(['code' => 'KB_DUPLICATE']);
});

test('KB-U21-10: delete aplica soft delete y oculta la KB', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant);

    $this->actingAs($owner)
        ->deleteJson(kb_url($tenant, $kb->id))
        ->assertOk();

    $this->assertSoftDeleted('knowledge_bases', ['id' => $kb->id]);

    $this->actingAs($owner)
        ->getJson(kb_url($tenant))
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->actingAs($owner)
        ->getJson(kb_url($tenant, $kb->id))
        ->assertStatus(404);
});

test('KB-U21-11: show de KB inexistente devuelve 404', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $fakeId = (string) Str::uuid();

    $this->actingAs($owner)
        ->getJson(kb_url($tenant, $fakeId))
        ->assertStatus(404);
});

// =========================================================================
// KB-U21-PERM-01..N -- PERMISSIONS
// =========================================================================

test('KB-U21-PERM-01: la matriz concede knowledge.view a todos y knowledge.manage solo a owner/admin', function (): void {
    $ownerPerms = TenantPermission::permissionsForRole(UserRole::Owner);
    $adminPerms = TenantPermission::permissionsForRole(UserRole::Admin);
    $agentPerms = TenantPermission::permissionsForRole(UserRole::Agent);

    expect($ownerPerms)->toContain(TenantPermission::ViewKnowledge)
        ->and($ownerPerms)->toContain(TenantPermission::ManageKnowledge)
        ->and($adminPerms)->toContain(TenantPermission::ViewKnowledge)
        ->and($adminPerms)->toContain(TenantPermission::ManageKnowledge)
        ->and($agentPerms)->toContain(TenantPermission::ViewKnowledge)
        ->and($agentPerms)->not->toContain(TenantPermission::ManageKnowledge);
});

test('KB-U21-PERM-02: el agente ve knowledge bases pero NO puede crear, editar ni eliminar', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $kb = make_kb($tenant);

    $this->actingAs($agent)->getJson(kb_url($tenant))->assertOk();
    $this->actingAs($agent)->getJson(kb_url($tenant, $kb->id))->assertOk();

    $this->actingAs($agent)
        ->postJson(kb_url($tenant), ['name' => 'Intento Agent'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->patchJson(kb_url($tenant, $kb->id), ['name' => 'Intento Update'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->deleteJson(kb_url($tenant, $kb->id))
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->assertDatabaseHas('knowledge_bases', ['id' => $kb->id, 'name' => $kb->name]);
});

test('KB-U21-PERM-03: el admin puede crear, editar y eliminar knowledge bases', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create();
    make_tenant_member($admin, $tenant, 'admin');

    $this->actingAs($admin)
        ->postJson(kb_url($tenant), ['name' => 'KB Admin'])
        ->assertStatus(201);

    $kb = KnowledgeBase::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    $this->actingAs($admin)
        ->patchJson(kb_url($tenant, $kb->id), ['name' => 'KB Admin Updated'])
        ->assertOk();

    $this->actingAs($admin)
        ->deleteJson(kb_url($tenant, $kb->id))
        ->assertOk();
});

// =========================================================================
// KB-U21-MT-01..10 -- MULTI-TENANCY
// =========================================================================

test('KB-U21-MT-01: Tenant A no lista KB de B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    make_kb($tenantB, ['name' => 'KB de B']);

    $this->actingAs($ownerA)
        ->getJson(kb_url($tenantB))
        ->assertStatus(404);
});

test('KB-U21-MT-02: Tenant A no puede show KB de B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    $kbB = make_kb($tenantB);

    $this->actingAs($ownerA)
        ->getJson(kb_url($tenantB, $kbB->id))
        ->assertStatus(404);
});

test('KB-U21-MT-03: Tenant A no puede update KB de B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    $kbB = make_kb($tenantB);

    $this->actingAs($ownerA)
        ->patchJson(kb_url($tenantB, $kbB->id), ['name' => 'Hackeado'])
        ->assertStatus(404);

    $this->assertDatabaseHas('knowledge_bases', ['id' => $kbB->id, 'name' => $kbB->name]);
});

test('KB-U21-MT-04: Tenant A no puede delete KB de B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    $kbB = make_kb($tenantB);

    $this->actingAs($ownerA)
        ->deleteJson(kb_url($tenantB, $kbB->id))
        ->assertStatus(404);

    $this->assertDatabaseHas('knowledge_bases', ['id' => $kbB->id]);
});

test('KB-U21-MT-05: manipular tenant_id en body no cambia ownership', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenantA, 'owner');

    $this->actingAs($owner)
        ->postJson(kb_url($tenantA), [
            'name' => 'KB Spoofed',
            'tenant_id' => $tenantB->id,
        ])
        ->assertStatus(201);

    $this->assertDatabaseHas('knowledge_bases', ['tenant_id' => $tenantA->id, 'name' => 'KB Spoofed']);
    $this->assertDatabaseMissing('knowledge_bases', ['tenant_id' => $tenantB->id, 'name' => 'KB Spoofed']);
});

test('KB-U21-MT-06: agent puede view knowledge bases', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $kb = make_kb($tenant);

    $this->actingAs($agent)
        ->getJson(kb_url($tenant))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($agent)
        ->getJson(kb_url($tenant, $kb->id))
        ->assertOk();
});

test('KB-U21-MT-07: agent NO puede manage knowledge bases', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $kb = make_kb($tenant);

    $this->actingAs($agent)
        ->postJson(kb_url($tenant), ['name' => 'Intento'])
        ->assertStatus(403);

    $this->actingAs($agent)
        ->patchJson(kb_url($tenant, $kb->id), ['name' => 'X'])
        ->assertStatus(403);

    $this->actingAs($agent)
        ->deleteJson(kb_url($tenant, $kb->id))
        ->assertStatus(403);
});

test('KB-U21-MT-08: inactive membership rechazada', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $owner = User::factory()->create();
    $owner->tenants()->attach($tenant, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
    $owner->forceFill(['current_tenant_id' => $tenant->id])->save();

    $this->actingAs($owner)
        ->getJson(kb_url($tenant))
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);
});

test('KB-U21-MT-09: cross-tenant document listing/show rechazado', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    $kbB = make_kb($tenantB);
    $docB = make_kb_document($tenantB, $kbB);

    $this->actingAs($ownerA)
        ->getJson(kb_doc_url($tenantB, $kbB->id))
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->getJson(kb_doc_url($tenantB, $kbB->id, $docB->id))
        ->assertStatus(404);
});

test('KB-U21-MT-10: route IDs manipulados no producen IDOR', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    $kbB = make_kb($tenantB);

    $this->actingAs($ownerA)
        ->patchJson(kb_url($tenantA, $kbB->id), ['name' => 'IDOR'])
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->deleteJson(kb_url($tenantA, $kbB->id))
        ->assertStatus(404);
});

// =========================================================================
// KB-U21-SEC-01..N -- SECURITY
// =========================================================================

test('KB-U21-SEC-01: tenant_id body injection es ignorado', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(kb_url($tenant), [
            'name' => 'KB Segura',
            'tenant_id' => (string) Str::uuid(),
        ])
        ->assertStatus(201);

    $this->assertDatabaseHas('knowledge_bases', ['tenant_id' => $tenant->id, 'name' => 'KB Segura']);
});

test('KB-U21-SEC-02: resource no expone file_hash', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant);
    $doc = make_kb_document($tenant, $kb, ['file_hash' => 'secret-hash-value']);

    $response = $this->actingAs($owner)
        ->getJson(kb_doc_url($tenant, $kb->id, $doc->id))
        ->assertOk();

    $response->assertJsonMissing(['file_hash' => 'secret-hash-value']);
});

test('KB-U21-SEC-03: resource no expone storage_disk ni storage_path', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant);
    $doc = make_kb_document($tenant, $kb);

    $response = $this->actingAs($owner)
        ->getJson(kb_doc_url($tenant, $kb->id, $doc->id))
        ->assertOk();

    $response->assertJsonMissing(['storage_disk' => 'minio']);
    $response->assertJsonMissing(['storage_path' => $doc->storage_path]);
});

test('KB-U21-SEC-04: cross-tenant UUID no produce IDOR en document show', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');

    $kbA = make_kb($tenantA);
    $kbB = make_kb($tenantB);
    $docB = make_kb_document($tenantB, $kbB);

    $this->actingAs($ownerA)
        ->getJson(kb_doc_url($tenantA, $kbA->id, $docB->id))
        ->assertStatus(404);
});

// =========================================================================
// KB-U21-AUD-01..N -- AUDIT
// =========================================================================

test('KB-U21-AUD-01: crear, actualizar y eliminar quedan auditados', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(kb_url($tenant), ['name' => 'KB Auditada'])
        ->assertStatus(201);

    $kbId = DB::table('knowledge_bases')->where('tenant_id', $tenant->id)->value('id');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'knowledge_base.created',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_type' => KnowledgeBase::class,
        'subject_id' => $kbId,
    ]);

    $this->actingAs($owner)
        ->patchJson(kb_url($tenant, $kbId), ['name' => 'KB Auditada v2'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'knowledge_base.updated',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_id' => $kbId,
    ]);

    $this->actingAs($owner)
        ->deleteJson(kb_url($tenant, $kbId))
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'knowledge_base.deleted',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_id' => $kbId,
    ]);
});

// =========================================================================
// KB-U21-DOC-01..N -- DOCUMENT API
// =========================================================================

test('KB-U21-DOC-01: document index retorna documentos de la KB', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant);
    make_kb_document($tenant, $kb, ['original_filename' => 'doc1.txt']);
    make_kb_document($tenant, $kb, ['original_filename' => 'doc2.txt']);

    $this->actingAs($owner)
        ->getJson(kb_doc_url($tenant, $kb->id))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'documents');
});

test('KB-U21-DOC-02: document show retorna el documento', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant);
    $doc = make_kb_document($tenant, $kb, ['original_filename' => 'test.pdf']);

    $this->actingAs($owner)
        ->getJson(kb_doc_url($tenant, $kb->id, $doc->id))
        ->assertOk()
        ->assertJsonPath('document.original_filename', 'test.pdf')
        ->assertJsonPath('document.status', 'uploaded');
});

test('KB-U21-DOC-03: document delete aplica soft delete', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant);
    $doc = make_kb_document($tenant, $kb);

    $this->actingAs($owner)
        ->deleteJson(kb_doc_url($tenant, $kb->id, $doc->id))
        ->assertOk();

    $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
});

test('KB-U21-DOC-04: document index de KB inexistente devuelve 404', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $fakeId = (string) Str::uuid();

    $this->actingAs($owner)
        ->getJson(kb_doc_url($tenant, $fakeId))
        ->assertStatus(404);
});

test('KB-U21-DOC-05: agente NO puede eliminar documentos', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $kb = make_kb($tenant);
    $doc = make_kb_document($tenant, $kb);

    $this->actingAs($agent)
        ->getJson(kb_doc_url($tenant, $kb->id))
        ->assertOk();

    $this->actingAs($agent)
        ->deleteJson(kb_doc_url($tenant, $kb->id, $doc->id))
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->assertDatabaseHas('knowledge_documents', ['id' => $doc->id]);
});

test('KB-U21-DOC-06: document search filtra por original_filename', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $kb = make_kb($tenant);
    make_kb_document($tenant, $kb, ['original_filename' => 'faq.pdf']);
    make_kb_document($tenant, $kb, ['original_filename' => 'manual.pdf']);
    make_kb_document($tenant, $kb, ['original_filename' => 'guia.txt']);

    $this->actingAs($owner)
        ->getJson(kb_doc_url($tenant, $kb->id).'?search=faq')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('documents.0.original_filename', 'faq.pdf');
});
