<?php

declare(strict_types=1);

use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\InboxConversationChangeKind;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\TenantUser;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\HandoffRequestMailNotification;
use App\Events\InboxConversationChanged;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
    NotificationFacade::fake();
});

beforeEach(function (): void {
    NotificationFacade::fake();

    $this->tenant = Tenant::factory()->create();
    TenantContext::setId($this->tenant->id);

    $this->owner = User::factory()->create(['email' => 'owner@test.com']);
    $this->admin = User::factory()->create(['email' => 'admin@test.com']);
    $this->agent = User::factory()->create(['email' => 'agent@test.com']);

    make_tenant_member($this->owner, $this->tenant, 'owner');
    make_tenant_member($this->admin, $this->tenant, 'admin');
    make_tenant_member($this->agent, $this->tenant, 'agent');

    $this->contact = make_contact($this->tenant);

    TenantContext::clear();
});

function createConversationFor(Tenant $tenant, Contact $contact): Conversation
{
    TenantContext::setId($tenant->id);

    try {
        return Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    } finally {
        TenantContext::clear();
    }
}

function enableEmailFor(User $user, Tenant $tenant): void
{
    TenantUser::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->update(['email_notifications_enabled' => true]);
}

function disableEmailFor(User $user, Tenant $tenant): void
{
    TenantUser::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->update(['email_notifications_enabled' => false]);
}

it('NOTIF-MAIL-01: handoff sends email to owner with email enabled', function (): void {
    enableEmailFor($this->owner, $this->tenant);

    $conversation = createConversationFor($this->tenant, $this->contact);

    event(new InboxConversationChanged(
        conversation: $conversation,
        kind: InboxConversationChangeKind::HandoffRequested,
    ));

    NotificationFacade::assertSentOnDemand(HandoffRequestMailNotification::class);
});

it('NOTIF-MAIL-02: handoff sends email to admin with email enabled', function (): void {
    disableEmailFor($this->owner, $this->tenant);
    enableEmailFor($this->admin, $this->tenant);

    $conversation = createConversationFor($this->tenant, $this->contact);

    event(new InboxConversationChanged(
        conversation: $conversation,
        kind: InboxConversationChangeKind::HandoffRequested,
    ));

    NotificationFacade::assertSentOnDemand(HandoffRequestMailNotification::class);
});

it('NOTIF-MAIL-03: agent not emailed on handoff', function (): void {
    disableEmailFor($this->owner, $this->tenant);
    disableEmailFor($this->admin, $this->tenant);
    enableEmailFor($this->agent, $this->tenant);

    $conversation = createConversationFor($this->tenant, $this->contact);

    event(new InboxConversationChanged(
        conversation: $conversation,
        kind: InboxConversationChangeKind::HandoffRequested,
    ));

    NotificationFacade::assertNothingSent();
});

it('NOTIF-MAIL-04: disabled owner not emailed', function (): void {
    disableEmailFor($this->owner, $this->tenant);
    disableEmailFor($this->admin, $this->tenant);

    $conversation = createConversationFor($this->tenant, $this->contact);

    event(new InboxConversationChanged(
        conversation: $conversation,
        kind: InboxConversationChangeKind::HandoffRequested,
    ));

    NotificationFacade::assertNothingSent();
});

it('NOTIF-MAIL-05: inactive admin not emailed', function (): void {
    enableEmailFor($this->admin, $this->tenant);

    TenantUser::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->admin->id)
        ->update(['status' => 'disabled']);

    $conversation = createConversationFor($this->tenant, $this->contact);

    event(new InboxConversationChanged(
        conversation: $conversation,
        kind: InboxConversationChangeKind::HandoffRequested,
    ));

    NotificationFacade::assertNothingSent();
});

it('NOTIF-MAIL-06: cross-tenant member not emailed', function (): void {
    $tenantB = Tenant::factory()->create();
    TenantContext::setId($tenantB->id);
    make_tenant_member($this->owner, $tenantB, 'owner');
    enableEmailFor($this->owner, $tenantB);
    TenantContext::clear();

    $conversation = createConversationFor($this->tenant, $this->contact);

    event(new InboxConversationChanged(
        conversation: $conversation,
        kind: InboxConversationChangeKind::HandoffRequested,
    ));

    NotificationFacade::assertNothingSent();
});

it('NOTIF-MAIL-07: generic content — no PII in mail', function (): void {
    $notification = new HandoffRequestMailNotification(tenantName: 'Test Tenant');
    $mailMessage = $notification->toMail($this->owner);

    $body = (string) $mailMessage->render();

    expect($body)->not->toContain('owner@test.com');
    expect($body)->not->toContain('+52');
    expect($body)->not->toContain('phone');
});

it('NOTIF-MAIL-08: no PII — email address not in mail body', function (): void {
    $notification = new HandoffRequestMailNotification(tenantName: 'Acme Corp');
    $mailMessage = $notification->toMail($this->owner);
    $body = (string) $mailMessage->render();

    expect($body)->toContain('Acme Corp');
    expect($body)->not->toContain($this->owner->email);
    expect($body)->not->toContain($this->owner->name);
});

it('NOTIF-MAIL-09: mail is queued via ShouldQueue', function (): void {
    $notification = new HandoffRequestMailNotification(tenantName: 'Test');
    expect($notification)->toBeInstanceOf(ShouldQueue::class);
});

it('NOTIF-MAIL-10: no email on non-handoff events', function (): void {
    enableEmailFor($this->owner, $this->tenant);

    $conversation = createConversationFor($this->tenant, $this->contact);

    event(new InboxConversationChanged(
        conversation: $conversation,
        kind: InboxConversationChangeKind::Claimed,
    ));

    NotificationFacade::assertNothingSent();
});
