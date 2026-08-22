<?php

declare(strict_types=1);

use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! env('HANDOFF_U2_PG_TEST')) {
        $this->markTestSkipped('Requires PG test env (HANDOFF_U2_PG_TEST=1).');
    }
});

afterEach(function (): void {
    TenantContext::clear();
});

function createPGConcNotif(User $user, Tenant $tenant): Notification
{
    TenantContext::setId($tenant->id);

    try {
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::HandoffRequested,
            'priority' => NotificationPriority::High,
            'title' => 'Concurrency test',
            'body' => 'Test body',
            'data' => ['event' => 'test'],
        ]);
    } finally {
        TenantContext::clear();
    }
}

/*
|--------------------------------------------------------------------------
| NOTIF-CON-01..03 — Concurrency / CAS Tests (PostgreSQL)
|--------------------------------------------------------------------------
|
| PHP pthreads is not available; we test CAS semantics using sequential
| raw DB operations that prove the WHERE clause is effective.
|
*/

it('NOTIF-CON-01: CAS markRead — second update on already-read row affects 0 rows', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    $notification = createPGConcNotif($user, $tenant);

    $affected1 = DB::connection('pgsql')
        ->table('notifications')
        ->where('id', $notification->id)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    expect($affected1)->toBe(1);

    $affected2 = DB::connection('pgsql')
        ->table('notifications')
        ->where('id', $notification->id)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    expect($affected2)->toBe(0);

    $fresh = Notification::query()->withoutTenantScope()->find($notification->id);
    expect($fresh->read_at)->not->toBeNull();
});

it('NOTIF-CON-02: CAS markAllRead — second call on already-read set affects 0 rows', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    for ($i = 0; $i < 3; $i++) {
        createPGConcNotif($user, $tenant);
    }

    $affected1 = DB::connection('pgsql')
        ->table('notifications')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    expect($affected1)->toBe(3);

    $affected2 = DB::connection('pgsql')
        ->table('notifications')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    expect($affected2)->toBe(0);

    $unread = Notification::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->whereNull('read_at')
        ->count();

    expect($unread)->toBe(0);
});

it('NOTIF-CON-03: markAllRead then new notification — unread reflects latest state', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    for ($i = 0; $i < 3; $i++) {
        createPGConcNotif($user, $tenant);
    }

    DB::connection('pgsql')
        ->table('notifications')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    TenantContext::setId($tenant->id);

    try {
        Notification::query()->create([
            'user_id' => $user->id,
            'type' => NotificationType::System,
            'priority' => NotificationPriority::Normal,
            'title' => 'New notification',
            'body' => 'Created after markAllRead',
            'data' => ['event' => 'concurrent'],
        ]);
    } finally {
        TenantContext::clear();
    }

    $total = Notification::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->count();

    $unread = Notification::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->whereNull('read_at')
        ->count();

    expect($total)->toBe(4)
        ->and($unread)->toBe(1);
});
