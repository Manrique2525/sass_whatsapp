<?php

declare(strict_types=1);

use App\Application\Contacts\Services\ContactService;
use App\Application\KnowledgeBase\Services\DocumentService;
use App\Application\Users\Services\InvitationService;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\InvitationStatus;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\TenantInvitation;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Models\WebhookEvent;
use App\Infrastructure\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/**
 * @param  array<string, int|null>  $limits
 */
function cap_u4_entitle(
    Tenant $tenant,
    array $limits,
    SubscriptionStatus $status = SubscriptionStatus::Active,
): Plan {
    $plan = Plan::factory()->create([
        'limits' => array_merge([
            'messages' => null,
            'ai_tokens' => null,
            'contacts' => null,
            'flow_executions' => null,
            'users' => null,
            'knowledge_documents' => null,
        ], $limits),
    ]);

    TenantContext::withId($tenant->id, fn (): Subscription => Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => $status,
    ]));

    return $plan;
}

function cap_u4_owner(Tenant $tenant): User
{
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenant, UserRole::Owner->value);

    return $owner;
}

function cap_u4_contact_url(Tenant $tenant): string
{
    return "/api/v1/tenants/{$tenant->id}/contacts";
}

function cap_u4_kb(Tenant $tenant): KnowledgeBase
{
    return TenantContext::withId($tenant->id, fn (): KnowledgeBase => KnowledgeBase::query()->create([
        'name' => 'Capacity KB '.Str::random(8),
    ]));
}

function cap_u4_document(
    Tenant $tenant,
    KnowledgeBase $knowledgeBase,
    KnowledgeDocumentStatus $status = KnowledgeDocumentStatus::Uploaded,
): KnowledgeDocument {
    return TenantContext::withId($tenant->id, fn (): KnowledgeDocument => KnowledgeDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'knowledge_base_id' => $knowledgeBase->id,
        'status' => $status,
        'file_hash' => hash('sha256', (string) Str::uuid()),
    ]));
}

function cap_u4_upload(User $user, Tenant $tenant, KnowledgeBase $knowledgeBase, string $suffix): KnowledgeDocument
{
    Storage::fake((string) config('knowledge.upload.storage_disk'));
    Queue::fake();

    return TenantContext::withId(
        $tenant->id,
        fn (): KnowledgeDocument => app(DocumentService::class)->upload(
            $user,
            $tenant,
            $knowledgeBase->id,
            UploadedFile::fake()->createWithContent(
                "capacity-{$suffix}.txt",
                "Capacity limit document {$suffix} with enough valid UTF-8 text for upload validation.",
            ),
        ),
    );
}

/**
 * @return array{TenantInvitation, string}
 */
function cap_u4_invitation(Tenant $tenant, User $actor, User $invited): array
{
    $token = Str::random(64);
    $invitation = TenantInvitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => $invited->email,
        'role' => UserRole::Agent,
        'token_hash' => hash('sha256', $token),
        'invited_by' => $actor->id,
        'status' => InvitationStatus::Pending,
        'expires_at' => now()->addDays(7),
    ]);

    return [$invitation, $token];
}

// Contacts: current non-deleted rows consume capacity.

test('CAP-U4-CONTACT-01: contact creation succeeds under the current capacity limit', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['contacts' => 2]);

    $this->actingAs($owner)->postJson(cap_u4_contact_url($tenant), [
        'name' => 'Capacity Contact',
        'phone' => '+15550000001',
    ])->assertCreated();
});

test('CAP-U4-CONTACT-02: contact creation consumes the exact last slot', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['contacts' => 2]);
    make_contact($tenant, ['phone' => '+15550000001']);

    $this->actingAs($owner)->postJson(cap_u4_contact_url($tenant), [
        'name' => 'Last Slot',
        'phone' => '+15550000002',
    ])->assertCreated();

    expect(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('CAP-U4-CONTACT-03: contact creation at limit returns a safe quota error', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['contacts' => 1]);
    make_contact($tenant, ['phone' => '+15550000001']);

    $this->actingAs($owner)->postJson(cap_u4_contact_url($tenant), [
        'name' => 'Blocked',
        'phone' => '+15550000002',
        'tenant_id' => (string) Str::uuid(),
    ])->assertStatus(429)
        ->assertJson([
            'code' => 'TENANT_QUOTA_EXCEEDED',
            'errors' => [
                'category' => 'contacts',
                'limit' => 1,
                'used' => 1,
            ],
        ])
        ->assertJsonMissingPath('tenant_id')
        ->assertJsonMissingPath('plan_id')
        ->assertJsonMissingPath('subscription_id');

    expect(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('CAP-U4-CONTACT-04: null contact limit is unlimited', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => null]);

    app(ContactService::class)->findOrCreateForPhone($tenant, '15550000001');
    app(ContactService::class)->findOrCreateForPhone($tenant, '15550000002');

    expect(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('CAP-U4-CONTACT-05: zero contact limit blocks every new contact', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 0]);

    expect(fn () => app(ContactService::class)->findOrCreateForPhone($tenant, '15550000001'))
        ->toThrow(TenantQuotaExceededException::class);
});

test('CAP-U4-CONTACT-06: an existing contact is returned while tenant is at limit', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 1]);
    $contact = make_contact($tenant, ['phone' => '+15550000001']);

    $resolved = app(ContactService::class)->findOrCreateForPhone($tenant, '+1 (555) 000-0001');

    expect($resolved->id)->toBe($contact->id)
        ->and(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('CAP-U4-CONTACT-07: manual contact creation cannot bypass capacity', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['contacts' => 1]);
    make_contact($tenant, ['phone' => '+15550000001']);

    expect(fn () => app(ContactService::class)->create($owner, $tenant, [
        'name' => 'Manual Blocked',
        'phone' => '+15550000002',
    ]))->toThrow(TenantQuotaExceededException::class);
});

test('CAP-U4-CONTACT-08: inbound auto-create is terminally blocked without retrying the webhook', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 0]);
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(whatsapp_webhook_payload('cap-contact-blocked', 'phone-1'))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'cap-contact-blocked')->firstOrFail();

    expect($event->status->value)->toBe('failed')
        ->and($event->error_code)->toBe('contact_quota_exceeded')
        ->and($event->processed_at)->not->toBeNull()
        ->and(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(Conversation::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(Message::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('CAP-U4-CONTACT-09: soft-deleted contact frees capacity', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 1]);
    $contact = make_contact($tenant, ['phone' => '+15550000001']);
    $contact->delete();

    $created = app(ContactService::class)->findOrCreateForPhone($tenant, '15550000002');

    expect($created->phone)->toBe('+15550000002')
        ->and(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('CAP-U4-CONTACT-10: contact capacity is isolated per tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    cap_u4_entitle($tenantA, ['contacts' => 1]);
    cap_u4_entitle($tenantB, ['contacts' => 1]);
    make_contact($tenantA, ['phone' => '+15550000001']);

    expect(fn () => app(ContactService::class)->findOrCreateForPhone($tenantA, '15550000002'))
        ->toThrow(TenantQuotaExceededException::class);

    $contactB = app(ContactService::class)->findOrCreateForPhone($tenantB, '15550000002');
    expect($contactB->tenant_id)->toBe($tenantB->id);
});

test('CAP-U4-CONTACT-11: active subscription allows contact capacity', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 1], SubscriptionStatus::Active);

    expect(app(ContactService::class)->findOrCreateForPhone($tenant, '15550000001'))->toBeInstanceOf(Contact::class);
});

test('CAP-U4-CONTACT-12: past-due subscription receives capacity grace', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 1], SubscriptionStatus::PastDue);

    expect(app(ContactService::class)->findOrCreateForPhone($tenant, '15550000001'))->toBeInstanceOf(Contact::class);
});

test('CAP-U4-CONTACT-13: pending subscription blocks contact creation', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 1], SubscriptionStatus::Pending);

    expect(fn () => app(ContactService::class)->findOrCreateForPhone($tenant, '15550000001'))
        ->toThrow(SubscriptionNotFoundException::class);
});

test('CAP-U4-CONTACT-14: cancelled subscription blocks contact creation', function (): void {
    $tenant = Tenant::factory()->create();
    cap_u4_entitle($tenant, ['contacts' => 1], SubscriptionStatus::Cancelled);

    expect(fn () => app(ContactService::class)->findOrCreateForPhone($tenant, '15550000001'))
        ->toThrow(SubscriptionNotFoundException::class);
});

test('CAP-U4-CONTACT-15: missing subscription blocks contact creation and inbound marks terminal failure', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);
    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    post_whatsapp_webhook(whatsapp_webhook_payload('cap-contact-no-sub', 'phone-1'))->assertOk();

    $event = WebhookEvent::query()->where('provider_event_id', 'cap-contact-no-sub')->firstOrFail();
    expect($event->status->value)->toBe('failed')
        ->and($event->error_code)->toBe('subscription_not_available')
        ->and(Contact::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

// Users: active memberships consume seats. Pending invitations do not.

test('CAP-U4-USER-01: accepting an invitation under limit activates the member', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['users' => 2]);
    $invited = User::factory()->create(['email' => 'capacity-user-1@example.test']);
    $token = invitation_token(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        $invited->email,
        UserRole::Agent,
    ));

    app(InvitationService::class)->accept($invited, $token);

    expect(TenantUser::query()->where('tenant_id', $tenant->id)->where('status', TenantMembershipStatus::Active)->count())->toBe(2);
});

test('CAP-U4-USER-02: invitation is blocked when active seats are at limit', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['users' => 1]);

    expect(fn () => app(InvitationService::class)->invite(
        $owner,
        $tenant,
        'blocked-seat@example.test',
        UserRole::Agent,
    ))->toThrow(TenantQuotaExceededException::class);
});

test('CAP-U4-USER-03: null user limit allows invitations and acceptance', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['users' => null]);
    $invited = User::factory()->create(['email' => 'unlimited-seat@example.test']);
    $token = invitation_token(fn () => app(InvitationService::class)->invite($owner, $tenant, $invited->email, UserRole::Agent));

    app(InvitationService::class)->accept($invited, $token);

    expect($invited->belongsToTenant($tenant))->toBeTrue();
});

test('CAP-U4-USER-04: zero user limit blocks new invitations', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['users' => 0]);

    try {
        app(InvitationService::class)->invite($owner, $tenant, 'zero-seat@example.test', UserRole::Agent);
        $this->fail('Expected users capacity failure.');
    } catch (TenantQuotaExceededException $exception) {
        expect($exception->used)->toBe(1)->and($exception->limit)->toBe(0);
    }
});

test('CAP-U4-USER-05: owner consumes one seat', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['users' => 1]);

    try {
        app(InvitationService::class)->invite($owner, $tenant, 'owner-counted@example.test', UserRole::Agent);
        $this->fail('Expected users capacity failure.');
    } catch (TenantQuotaExceededException $exception) {
        expect($exception->category)->toBe('users')->and($exception->used)->toBe(1);
    }
});

test('CAP-U4-USER-06: owner admin and agent memberships all consume seats', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    $admin = User::factory()->create();
    $agent = User::factory()->create();
    make_tenant_member($admin, $tenant, UserRole::Admin->value);
    make_tenant_member($agent, $tenant, UserRole::Agent->value);
    $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
    cap_u4_entitle($tenant, ['users' => 3]);

    try {
        app(InvitationService::class)->invite($owner, $tenant, 'all-roles-count@example.test', UserRole::Agent);
        $this->fail('Expected users capacity failure.');
    } catch (TenantQuotaExceededException $exception) {
        expect($exception->used)->toBe(3);
    }
});

test('CAP-U4-USER-07: pending invitations do not consume seats', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['users' => 2]);

    app(InvitationService::class)->invite($owner, $tenant, 'pending-a@example.test', UserRole::Agent);
    app(InvitationService::class)->invite($owner, $tenant, 'pending-b@example.test', UserRole::Agent);

    expect(TenantInvitation::query()->where('tenant_id', $tenant->id)->where('status', InvitationStatus::Pending)->count())->toBe(2)
        ->and(TenantUser::query()->where('tenant_id', $tenant->id)->where('status', TenantMembershipStatus::Active)->count())->toBe(1);
});

test('CAP-U4-USER-08: stale invitation acceptance after downgrade is blocked atomically', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    $plan = cap_u4_entitle($tenant, ['users' => 2]);
    $invited = User::factory()->create(['email' => 'stale-invite@example.test']);
    $token = invitation_token(fn () => app(InvitationService::class)->invite($owner, $tenant, $invited->email, UserRole::Agent));
    $limits = $plan->limits;
    $limits['users'] = 1;
    $plan->forceFill(['limits' => $limits])->save();

    expect(fn () => app(InvitationService::class)->accept($invited, $token))
        ->toThrow(TenantQuotaExceededException::class);

    expect(TenantInvitation::query()->where('token_hash', hash('sha256', $token))->value('status'))->toBe(InvitationStatus::Pending)
        ->and(TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $invited->id)->exists())->toBeFalse();
});

test('CAP-U4-USER-09: existing active membership is unaffected at capacity', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    $member = User::factory()->create(['email' => 'existing-seat@example.test']);
    make_tenant_member($member, $tenant, UserRole::Agent->value);
    $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
    cap_u4_entitle($tenant, ['users' => 2]);
    [$invitation, $token] = cap_u4_invitation($tenant, $owner, $member);

    app(InvitationService::class)->accept($member, $token);

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Accepted)
        ->and(TenantUser::query()->where('tenant_id', $tenant->id)->where('status', TenantMembershipStatus::Active)->count())->toBe(2);
});

test('CAP-U4-USER-10: user capacity is isolated per tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = cap_u4_owner($tenantA);
    $ownerB = cap_u4_owner($tenantB);
    cap_u4_entitle($tenantA, ['users' => 1]);
    cap_u4_entitle($tenantB, ['users' => 2]);

    expect(fn () => app(InvitationService::class)->invite($ownerA, $tenantA, 'tenant-a@example.test', UserRole::Agent))
        ->toThrow(TenantQuotaExceededException::class);

    $invitationB = app(InvitationService::class)->invite($ownerB, $tenantB, 'tenant-b@example.test', UserRole::Agent);
    expect($invitationB->tenant_id)->toBe($tenantB->id);
});

test('CAP-U4-USER-11: user entitlement status matrix is fail-closed except active and past-due', function (): void {
    foreach ([SubscriptionStatus::Active, SubscriptionStatus::PastDue] as $status) {
        $tenant = Tenant::factory()->create();
        $owner = cap_u4_owner($tenant);
        cap_u4_entitle($tenant, ['users' => 2], $status);
        expect(app(InvitationService::class)->invite($owner, $tenant, "allowed-{$status->value}@example.test", UserRole::Agent))
            ->toBeInstanceOf(TenantInvitation::class);
    }

    foreach ([SubscriptionStatus::Pending, SubscriptionStatus::Cancelled] as $status) {
        $tenant = Tenant::factory()->create();
        $owner = cap_u4_owner($tenant);
        cap_u4_entitle($tenant, ['users' => 2], $status);
        expect(fn () => app(InvitationService::class)->invite($owner, $tenant, "blocked-{$status->value}@example.test", UserRole::Agent))
            ->toThrow(SubscriptionNotFoundException::class);
    }

    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    expect(fn () => app(InvitationService::class)->invite($owner, $tenant, 'missing-sub@example.test', UserRole::Agent))
        ->toThrow(SubscriptionNotFoundException::class);
});

// Knowledge documents: all non-deleted lifecycle states consume capacity.

test('CAP-U4-KB-01: document upload succeeds under limit', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => 2]);
    $kb = cap_u4_kb($tenant);

    expect(cap_u4_upload($owner, $tenant, $kb, 'under-limit'))->toBeInstanceOf(KnowledgeDocument::class);
});

test('CAP-U4-KB-02: document upload at limit is blocked', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => 1]);
    $kb = cap_u4_kb($tenant);
    cap_u4_document($tenant, $kb);

    expect(fn () => cap_u4_upload($owner, $tenant, $kb, 'at-limit'))
        ->toThrow(TenantQuotaExceededException::class);
});

test('CAP-U4-KB-03: null document limit is unlimited', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => null]);
    $kb = cap_u4_kb($tenant);

    cap_u4_upload($owner, $tenant, $kb, 'unlimited-a');
    cap_u4_upload($owner, $tenant, $kb, 'unlimited-b');

    expect(KnowledgeDocument::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('CAP-U4-KB-04: zero document limit blocks upload', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => 0]);
    $kb = cap_u4_kb($tenant);

    expect(fn () => cap_u4_upload($owner, $tenant, $kb, 'zero'))
        ->toThrow(TenantQuotaExceededException::class);
});

test('CAP-U4-KB-05: blocked upload performs no irreversible storage or queue work', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => 0]);
    $kb = cap_u4_kb($tenant);

    try {
        cap_u4_upload($owner, $tenant, $kb, 'no-storage');
        $this->fail('Expected document capacity failure.');
    } catch (TenantQuotaExceededException) {
        expect(Storage::disk((string) config('knowledge.upload.storage_disk'))->allFiles())->toBe([]);
        Queue::assertNothingPushed();
    }
});

test('CAP-U4-KB-06: failed document still consumes capacity', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => 1]);
    $kb = cap_u4_kb($tenant);
    cap_u4_document($tenant, $kb, KnowledgeDocumentStatus::Failed);

    expect(fn () => cap_u4_upload($owner, $tenant, $kb, 'failed-counts'))
        ->toThrow(TenantQuotaExceededException::class);
});

test('CAP-U4-KB-07: soft-deleted document frees capacity', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => 1]);
    $kb = cap_u4_kb($tenant);
    $document = cap_u4_document($tenant, $kb, KnowledgeDocumentStatus::Ready);
    $document->delete();

    expect(cap_u4_upload($owner, $tenant, $kb, 'after-delete'))->toBeInstanceOf(KnowledgeDocument::class);
});

test('CAP-U4-KB-08: document capacity is isolated per tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ownerA = cap_u4_owner($tenantA);
    $ownerB = cap_u4_owner($tenantB);
    cap_u4_entitle($tenantA, ['knowledge_documents' => 1]);
    cap_u4_entitle($tenantB, ['knowledge_documents' => 1]);
    $kbA = cap_u4_kb($tenantA);
    $kbB = cap_u4_kb($tenantB);
    cap_u4_document($tenantA, $kbA);

    expect(fn () => cap_u4_upload($ownerA, $tenantA, $kbA, 'tenant-a'))
        ->toThrow(TenantQuotaExceededException::class);
    expect(cap_u4_upload($ownerB, $tenantB, $kbB, 'tenant-b'))->toBeInstanceOf(KnowledgeDocument::class);
});

test('CAP-U4-KB-09: document entitlement status matrix is fail-closed except active and past-due', function (): void {
    foreach ([SubscriptionStatus::Active, SubscriptionStatus::PastDue] as $status) {
        $tenant = Tenant::factory()->create();
        $owner = cap_u4_owner($tenant);
        cap_u4_entitle($tenant, ['knowledge_documents' => 1], $status);
        $kb = cap_u4_kb($tenant);
        expect(cap_u4_upload($owner, $tenant, $kb, "allowed-{$status->value}"))->toBeInstanceOf(KnowledgeDocument::class);
    }

    foreach ([SubscriptionStatus::Pending, SubscriptionStatus::Cancelled] as $status) {
        $tenant = Tenant::factory()->create();
        $owner = cap_u4_owner($tenant);
        cap_u4_entitle($tenant, ['knowledge_documents' => 1], $status);
        $kb = cap_u4_kb($tenant);
        expect(fn () => cap_u4_upload($owner, $tenant, $kb, "blocked-{$status->value}"))
            ->toThrow(SubscriptionNotFoundException::class);
    }

    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    $kb = cap_u4_kb($tenant);
    expect(fn () => cap_u4_upload($owner, $tenant, $kb, 'missing-sub'))
        ->toThrow(SubscriptionNotFoundException::class);
});

test('CAP-U4-KB-10: document capacity does not use ledger or embedding tokens', function (): void {
    $tenant = Tenant::factory()->create();
    $owner = cap_u4_owner($tenant);
    cap_u4_entitle($tenant, ['knowledge_documents' => 1, 'ai_tokens' => 1]);
    $kb = cap_u4_kb($tenant);

    cap_u4_upload($owner, $tenant, $kb, 'separate-token-quota');

    expect(UsageRecord::withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(UsageRecord::withoutTenantScope()->where('category', UsageCategory::KnowledgeDocuments)->count())->toBe(0)
        ->and(UsageRecord::withoutTenantScope()->where('category', UsageCategory::AiTokens)->count())->toBe(0);
});
