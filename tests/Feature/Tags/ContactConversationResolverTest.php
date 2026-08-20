<?php

declare(strict_types=1);

use App\Application\Contacts\Services\ContactConversationResolver;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\Tag;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ContactConversationResolver Tests (FASE 20 U3)
|--------------------------------------------------------------------------
|
| TAG-CONV-01..08 — Resolver: most recent conversation, tenant-safe,
| no filter bot_paused, ordered deterministically.
| Corren en SQLite :memory:.
|
*/

afterEach(function (): void {
    TenantContext::clear();
});

function create_conv_tenant(): Tenant
{
    return Tenant::factory()->create();
}

function create_conv_contact(Tenant $tenant, string $phone): Contact
{
    return TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Conv Contact',
        'phone' => $phone,
    ]));
}

function create_conv_conversation(
    Tenant $tenant,
    Contact $contact,
    string $status = ConversationStatus::Open->value,
): Conversation {
    return TenantContext::withId($tenant->id, fn () => Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'channel' => 'whatsapp',
        'status' => $status,
    ]));
}

it('TAG-CONV-01: resolves most recent conversation by updated_at', function (): void {
    $tenant = create_conv_tenant();
    $contact = create_conv_contact($tenant, '+529940000001');

    $old = create_conv_conversation($tenant, $contact);
    $recent = create_conv_conversation($tenant, $contact);

    DB::table('conversations')->where('id', $old->id)->update([
        'updated_at' => now()->subDays(5),
    ]);
    DB::table('conversations')->where('id', $recent->id)->update([
        'updated_at' => now()->subDays(1),
    ]);

    $resolver = app(ContactConversationResolver::class);
    $result = $resolver->resolveForTagAssignment($tenant, $contact);

    expect($result->id)->toBe($recent->id);
})->group('TAG-CONV-01');

it('TAG-CONV-02: returns null when no conversations exist', function (): void {
    $tenant = create_conv_tenant();
    $contact = create_conv_contact($tenant, '+529940000002');

    $resolver = app(ContactConversationResolver::class);
    $result = $resolver->resolveForTagAssignment($tenant, $contact);

    expect($result)->toBeNull();
})->group('TAG-CONV-02');

it('TAG-CONV-03: tenant-scoped — ignores conversations from other tenants', function (): void {
    $tenantA = create_conv_tenant();
    $contactA = create_conv_contact($tenantA, '+529940000003');
    $convA = create_conv_conversation($tenantA, $contactA);

    $tenantB = create_conv_tenant();
    $contactB = create_conv_contact($tenantB, '+529940000004');
    $convB = create_conv_conversation($tenantB, $contactB);

    $resolver = app(ContactConversationResolver::class);
    $resultA = $resolver->resolveForTagAssignment($tenantA, $contactA);

    expect($resultA->id)->toBe($convA->id);
})->group('TAG-CONV-03');

it('TAG-CONV-04: deterministic ordering — uses created_at ASC as tiebreaker', function (): void {
    $tenant = create_conv_tenant();
    $contact = create_conv_contact($tenant, '+529940000005');

    $conv1 = create_conv_conversation($tenant, $contact);
    $conv2 = create_conv_conversation($tenant, $contact);

    $resolver = app(ContactConversationResolver::class);
    $result = $resolver->resolveForTagAssignment($tenant, $contact);

    expect($result->id)->toBe($conv1->id);
})->group('TAG-CONV-04');

it('TAG-CONV-05: does not filter by bot_paused', function (): void {
    $tenant = create_conv_tenant();
    $contact = create_conv_contact($tenant, '+529940000006');

    $conv = create_conv_conversation($tenant, $contact, ConversationStatus::Pending->value);

    $resolver = app(ContactConversationResolver::class);
    $result = $resolver->resolveForTagAssignment($tenant, $contact);

    expect($result->id)->toBe($conv->id);
})->group('TAG-CONV-05');

it('TAG-CONV-06: id deterministic ordering — uses id ASC as final tiebreaker', function (): void {
    $tenant = create_conv_tenant();
    $contact = create_conv_contact($tenant, '+529940000007');

    $conv1 = create_conv_conversation($tenant, $contact);
    $conv2 = create_conv_conversation($tenant, $contact);

    $resolver = app(ContactConversationResolver::class);
    $result = $resolver->resolveForTagAssignment($tenant, $contact);

    expect($result->id)->toBe($conv1->id);
})->group('TAG-CONV-06');

it('TAG-CONV-07: resolves for correct contact within same tenant', function (): void {
    $tenant = create_conv_tenant();
    $contact1 = create_conv_contact($tenant, '+529940000008');
    $contact2 = create_conv_contact($tenant, '+529940000009');

    $conv1 = create_conv_conversation($tenant, $contact1);
    $conv2 = create_conv_conversation($tenant, $contact2);

    $resolver = app(ContactConversationResolver::class);
    $result1 = $resolver->resolveForTagAssignment($tenant, $contact1);
    $result2 = $resolver->resolveForTagAssignment($tenant, $contact2);

    expect($result1->id)->toBe($conv1->id);
    expect($result2->id)->toBe($conv2->id);
})->group('TAG-CONV-07');

it('TAG-CONV-08: archived conversation is still resolved (no status filter)', function (): void {
    $tenant = create_conv_tenant();
    $contact = create_conv_contact($tenant, '+529940000010');

    $conv = create_conv_conversation($tenant, $contact, ConversationStatus::Archived->value);

    $resolver = app(ContactConversationResolver::class);
    $result = $resolver->resolveForTagAssignment($tenant, $contact);

    expect($result->id)->toBe($conv->id);
})->group('TAG-CONV-08');
