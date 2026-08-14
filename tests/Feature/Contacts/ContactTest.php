<?php

declare(strict_types=1);

use App\Application\Contacts\Services\ContactService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 7 — CONTACTOS (CRM básico)
|--------------------------------------------------------------------------
*/

function contact_url(Tenant $tenant, ?string $contactId = null): string
{
    $base = '/api/v1/tenants/'.$tenant->id.'/contacts';

    return $contactId === null ? $base : $base.'/'.$contactId;
}

test('CONTACT-1: crear un contacto devuelve 201 y lo persiste normalizado', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)
        ->postJson(contact_url($tenant), [
            'name' => 'Ana García',
            'phone' => '+54 11 5555 4444',
            'email' => 'ana@example.com',
            'metadata' => ['origen' => 'whatsapp'],
        ])
        ->assertStatus(201)
        ->assertJsonPath('contact.phone', '+541155554444')
        ->assertJsonPath('contact.name', 'Ana García')
        ->assertJsonPath('contact.email', 'ana@example.com')
        ->assertJsonPath('contact.metadata.origen', 'whatsapp');

    $this->assertDatabaseHas('contacts', [
        'tenant_id' => $tenant->id,
        'phone' => '+541155554444',
        'name' => 'Ana García',
    ]);
});

test('CONTACT-2: crear valida el teléfono y el nombre', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)->postJson(contact_url($tenant), ['name' => 'Sin teléfono'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');

    $this->actingAs($owner)->postJson(contact_url($tenant), ['name' => 'Solo nombre'])
        ->assertStatus(422);

    $this->actingAs($owner)->postJson(contact_url($tenant), [
        'name' => 'Caracteres inválidos',
        'phone' => 'abc',
    ])->assertStatus(422)->assertJsonValidationErrors('phone');

    $this->actingAs($owner)->postJson(contact_url($tenant), [
        'name' => 'Pocos dígitos',
        'phone' => '123456',
    ])->assertStatus(422)->assertJsonValidationErrors('phone');

    $this->actingAs($owner)->postJson(contact_url($tenant), [
        'name' => 'Demasiados dígitos',
        'phone' => '1234567890123456789',
    ])->assertStatus(422)->assertJsonValidationErrors('phone');

    $this->actingAs($owner)->postJson(contact_url($tenant), [
        'phone' => '+541155554444',
    ])->assertStatus(422)->assertJsonValidationErrors('name');
});

test('CONTACT-3: crear normaliza formatos equivalentes de teléfono', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)->postJson(contact_url($tenant), [
        'name' => 'Sin prefijo',
        'phone' => '54911-5555-4444',
    ])->assertStatus(201)->assertJsonPath('contact.phone', '+5491155554444');
});

test('CONTACT-4: crear con teléfono duplicado activo devuelve 409 CONTACT_DUPLICATE', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_contact($tenant, ['phone' => '+541155554444']);

    $this->actingAs($owner)
        ->postJson(contact_url($tenant), [
            'name' => 'Duplicado',
            'phone' => '541155554444',
        ])
        ->assertStatus(409)
        ->assertJson(['code' => 'CONTACT_DUPLICATE']);
});

test('CONTACT-5: tras un soft delete se puede re-crear el mismo teléfono', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant, ['phone' => '+541155554444']);

    $this->actingAs($owner)
        ->deleteJson(contact_url($tenant, $contact->id))
        ->assertOk();

    $this->actingAs($owner)
        ->postJson(contact_url($tenant), [
            'name' => 'Re-creado',
            'phone' => '+541155554444',
        ])
        ->assertStatus(201);
});

test('CONTACT-6: index pagina y filtra por search, phone y email', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    make_contact($tenant, ['name' => 'Ana García', 'phone' => '+541155554444', 'email' => 'ana@example.com']);
    make_contact($tenant, ['name' => 'Bruno López', 'phone' => '+5491155553333', 'email' => 'bruno@example.com']);
    make_contact($tenant, ['name' => 'Carla Díaz', 'phone' => '+598915553333', 'email' => 'carla@example.com']);

    $this->actingAs($owner)
        ->getJson(contact_url($tenant))
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonCount(3, 'contacts');

    $this->actingAs($owner)
        ->getJson(contact_url($tenant).'?search=ana')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('contacts.0.name', 'Ana García');

    $this->actingAs($owner)
        ->getJson(contact_url($tenant).'?phone=54911')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('contacts.0.name', 'Bruno López');

    $this->actingAs($owner)
        ->getJson(contact_url($tenant).'?email=carla')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('contacts.0.name', 'Carla Díaz');
});

test('CONTACT-7: show devuelve el contacto solicitado', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant, ['name' => 'Ana García']);

    $this->actingAs($owner)
        ->getJson(contact_url($tenant, $contact->id))
        ->assertOk()
        ->assertJsonPath('contact.id', $contact->id)
        ->assertJsonPath('contact.name', 'Ana García');
});

test('CONTACT-8: update parcial actualiza y normaliza el teléfono', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant, ['name' => 'Ana García', 'phone' => '+541155554444']);

    $this->actingAs($owner)
        ->patchJson(contact_url($tenant, $contact->id), [
            'name' => 'Ana García Pérez',
            'phone' => '(54) 11 5555-4444',
            'email' => 'ana.nueva@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('contact.name', 'Ana García Pérez')
        ->assertJsonPath('contact.phone', '+541155554444')
        ->assertJsonPath('contact.email', 'ana.nueva@example.com');
});

test('CONTACT-9: update a un teléfono de otro contacto devuelve 409', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant, ['phone' => '+541155554444']);
    make_contact($tenant, ['phone' => '+5491155553333']);

    $this->actingAs($owner)
        ->patchJson(contact_url($tenant, $contact->id), ['phone' => '5491155553333'])
        ->assertStatus(409)
        ->assertJson(['code' => 'CONTACT_DUPLICATE']);
});

test('CONTACT-10: delete aplica soft delete y oculta el contacto', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $contact = make_contact($tenant);

    $this->actingAs($owner)
        ->deleteJson(contact_url($tenant, $contact->id))
        ->assertOk();

    $this->assertSoftDeleted('contacts', ['id' => $contact->id]);

    $this->actingAs($owner)
        ->getJson(contact_url($tenant))
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->actingAs($owner)
        ->getJson(contact_url($tenant, $contact->id))
        ->assertStatus(404);
});

test('CONTACT-11: el agente ve contactos pero NO puede crear, editar ni eliminar', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($agent, $tenant, 'agent');

    $contact = make_contact($tenant);

    $this->actingAs($agent)->getJson(contact_url($tenant))->assertOk();
    $this->actingAs($agent)->getJson(contact_url($tenant, $contact->id))->assertOk();

    $this->actingAs($agent)
        ->postJson(contact_url($tenant), ['name' => 'Intento', 'phone' => '+541155558888'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->patchJson(contact_url($tenant, $contact->id), ['name' => 'Intento'])
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->actingAs($agent)
        ->deleteJson(contact_url($tenant, $contact->id))
        ->assertStatus(403)
        ->assertJson(['code' => 'PERMISSION_DENIED']);

    $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'name' => $contact->name]);
});

test('CRITICO CONTACT-12: Tenant A jamás lee, modifica ni elimina contactos de Tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    make_tenant_member($ownerA, $tenantA, 'owner');
    make_tenant_member($ownerB, $tenantB, 'owner');

    $contactB = make_contact($tenantB, ['name' => 'Cliente de B', 'phone' => '+541155554444']);

    // A no ve los contactos de B ni los del endpoint de B.
    $this->actingAs($ownerA)
        ->getJson(contact_url($tenantB))
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->getJson(contact_url($tenantB, $contactB->id))
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->patchJson(contact_url($tenantB, $contactB->id), ['name' => 'Hackeado'])
        ->assertStatus(404);

    $this->actingAs($ownerA)
        ->deleteJson(contact_url($tenantB, $contactB->id))
        ->assertStatus(404);

    $this->assertDatabaseHas('contacts', [
        'id' => $contactB->id,
        'name' => 'Cliente de B',
    ]);
    $this->assertNull(Contact::query()->withoutTenantScope()->find($contactB->id)->deleted_at);
});

test('CONTACT-13: un tenant_id enviado en el cuerpo es ignorado', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $owner = User::factory()->create();
    make_tenant_member($owner, $tenantA, 'owner');

    $this->actingAs($owner)
        ->postJson(contact_url($tenantA), [
            'name' => 'Con spoofing',
            'phone' => '+541155554444',
            'tenant_id' => $tenantB->id,
        ])
        ->assertStatus(201);

    $this->assertDatabaseHas('contacts', ['tenant_id' => $tenantA->id, 'name' => 'Con spoofing']);
    $this->assertDatabaseMissing('contacts', ['tenant_id' => $tenantB->id]);
});

test('CONTACT-14: la matriz concede contacts.view a todos y contacts.manage solo a owner/admin', function (): void {
    $ownerPerms = TenantPermission::permissionsForRole(UserRole::Owner);
    $adminPerms = TenantPermission::permissionsForRole(UserRole::Admin);
    $agentPerms = TenantPermission::permissionsForRole(UserRole::Agent);

    expect($ownerPerms)->toContain(TenantPermission::ViewContacts)
        ->and($ownerPerms)->toContain(TenantPermission::ManageContacts)
        ->and($adminPerms)->toContain(TenantPermission::ViewContacts)
        ->and($adminPerms)->toContain(TenantPermission::ManageContacts)
        ->and($agentPerms)->toContain(TenantPermission::ViewContacts)
        ->and($agentPerms)->not->toContain(TenantPermission::ManageContacts);
});

test('CONTACT-15: crear, actualizar y eliminar quedan auditados', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, 'owner');

    $this->actingAs($owner)->postJson(contact_url($tenant), [
        'name' => 'Auditado',
        'phone' => '+541155554444',
    ])->assertStatus(201);

    $contactId = DB::table('contacts')->where('tenant_id', $tenant->id)->value('id');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'contact.created',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_type' => Contact::class,
        'subject_id' => $contactId,
    ]);

    $this->actingAs($owner)
        ->patchJson(contact_url($tenant, $contactId), ['name' => 'Auditado v2'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'contact.updated',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_id' => $contactId,
    ]);

    $audit = AuditLog::query()->where('action', 'contact.updated')->firstOrFail();
    expect($audit->data['changed'])->toContain('name');

    $this->actingAs($owner)
        ->deleteJson(contact_url($tenant, $contactId))
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'contact.deleted',
        'tenant_id' => $tenant->id,
        'actor_user_id' => $owner->id,
        'subject_id' => $contactId,
    ]);
});

test('CONTACT-16: un no-miembro del tenant recibe 404', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $stranger = User::factory()->create();
    make_tenant_member($stranger, $other, 'owner');

    $contact = make_contact($tenant);

    $this->actingAs($stranger)
        ->getJson(contact_url($tenant, $contact->id))
        ->assertStatus(404);
});

test('CONTACT-17: con tenant suspendido como activo, el acceso es denegado', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $owner = User::factory()->create();
    $owner->tenants()->attach($tenant, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
    $owner->forceFill(['current_tenant_id' => $tenant->id])->save();

    $this->actingAs($owner)
        ->getJson(contact_url($tenant))
        ->assertStatus(403)
        ->assertJson(['code' => 'NO_TENANT']);
});

test('CONTACT-18: otro tenant requiere switch previo', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $user = User::factory()->create();
    make_tenant_member($user, $tenantA, 'owner');
    $user->tenants()->attach($tenantB, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

    $contactB = make_contact($tenantB);

    $user->forceFill(['current_tenant_id' => $tenantA->id])->save();
    $this->actingAs($user)
        ->getJson(contact_url($tenantB, $contactB->id))
        ->assertStatus(404);

    $user->forceFill(['current_tenant_id' => $tenantB->id])->save();
    $this->actingAs($user)
        ->getJson(contact_url($tenantB, $contactB->id))
        ->assertOk();
});

test('CONTACT-19: findOrCreateForPhone reutiliza el contacto o lo crea (FASE 9)', function (): void {
    $tenant = Tenant::factory()->create();
    $service = app(ContactService::class);

    $created = $service->findOrCreateForPhone($tenant, '5491155554444');

    expect($created->phone)->toBe('+5491155554444')
        ->and($created->tenant_id)->toBe($tenant->id)
        ->and($created->name)->toBe('+5491155554444');

    $again = $service->findOrCreateForPhone($tenant, '+54 911 5555-4444');

    expect($again->id)->toBe($created->id);

    $this->assertDatabaseCount('contacts', 1);
    expect(TenantContext::id())->toBeNull();
});
