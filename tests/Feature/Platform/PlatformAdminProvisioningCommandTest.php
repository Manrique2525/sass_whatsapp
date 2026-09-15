<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantMembershipStatus;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\PlatformMfaCredential;
use App\Domain\Users\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

test('creates an unverified global admin without tenant or MFA and sends verification', function (): void {
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Email', 'admin-command@example.test')
        ->expectsQuestion('Name', 'Command Admin')
        ->expectsQuestion('Password', 'strong-password')
        ->expectsQuestion('Password confirmation', 'strong-password')
        ->expectsOutputToContain('Platform Admin created.')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'admin-command@example.test')->firstOrFail();

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->tenantUsers()->count())->toBe(0)
        ->and($user->platformMfaCredential)->toBeNull()
        ->and($user->roleForTenant('00000000-0000-0000-0000-000000000000'))->toBeNull()
        ->and($user->getRawOriginal('password'))->not->toBe('strong-password')
        ->and(Hash::check('strong-password', (string) $user->getRawOriginal('password')))->toBeTrue();

    expect(
        DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.name', UserRole::SuperAdmin->value)
            ->value('model_has_roles.tenant_id'),
    )->toBe(UserRole::GLOBAL_TEAM_ID);

    Notification::assertSentTo($user, VerifyEmail::class);
    $audit = DB::table('audit_logs')->where('action', 'platform.admin.created')->first();
    expect($audit)->not->toBeNull()
        ->and((string) $audit->data)->toContain((string) $user->id)
        ->and((string) $audit->data)->not->toContain('strong-password');
    $response = $this->actingAs($user)->get('/platform');
    expect($response->status())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('/verify-email');
});

test('promotes an existing user without changing password and is idempotent for an existing admin', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'existing@example.test', 'password' => 'original-password']);
    $storedPassword = $user->getRawOriginal('password');

    $this->artisan('platform-admin:create')
        ->expectsQuestion('Email', 'existing@example.test')
        ->expectsConfirmation('Promote this existing user to Platform Super Admin?', 'yes')
        ->expectsOutputToContain('Platform Admin promoted.')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->getRawOriginal('password'))->toBe($storedPassword)
        ->and($user->tenantUsers()->count())->toBe(0);
    Notification::assertSentTo($user, VerifyEmail::class);
    expect(DB::table('audit_logs')->where('action', 'platform.admin.promoted')->count())->toBe(1);

    Notification::fake();
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Email', 'existing@example.test')
        ->expectsOutputToContain('already exists; no changes made.')
        ->assertExitCode(0);

    Notification::assertNothingSent();
});

test('rejects invalid email and password confirmation without creating a user', function (): void {
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Email', 'not-an-email')
        ->assertExitCode(1);

    $this->artisan('platform-admin:create')
        ->expectsQuestion('Email', 'invalid-password@example.test')
        ->expectsQuestion('Name', 'Invalid Password')
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Password confirmation', 'different')
        ->assertExitCode(1);

    expect(User::query()->whereIn('email', ['not-an-email', 'invalid-password@example.test'])->count())->toBe(0);
    Notification::assertNothingSent();
});

test('rolls back new user when global role assignment fails', function (): void {
    Role::query()->where('name', UserRole::SuperAdmin->value)->delete();

    $this->artisan('platform-admin:create')
        ->expectsQuestion('Email', 'rollback@example.test')
        ->expectsQuestion('Name', 'Rollback Admin')
        ->expectsQuestion('Password', 'strong-password')
        ->expectsQuestion('Password confirmation', 'strong-password')
        ->assertExitCode(1);

    expect(User::query()->where('email', 'rollback@example.test')->exists())->toBeFalse()
        ->and(PlatformMfaCredential::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('requires MFA after email verification before platform access', function (): void {
    $this->artisan('platform-admin:create')
        ->expectsQuestion('Email', 'mfa-gate@example.test')
        ->expectsQuestion('Name', 'MFA Gate')
        ->expectsQuestion('Password', 'strong-password')
        ->expectsQuestion('Password confirmation', 'strong-password')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'mfa-gate@example.test')->firstOrFail();
    $user->markEmailAsVerified();

    $this->actingAs($user)->get('/platform')->assertRedirect('/platform/security');
    expect($user->isSuperAdmin())->toBeTrue();
});

test('rejects non-interactive execution', function (): void {
    $this->artisan('platform-admin:create --no-interaction')
        ->expectsOutputToContain('requires an interactive terminal')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('local command creates a verified global admin without tenant or MFA', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->artisan('local:platform-admin')
        ->expectsOutputToContain('LOCAL DEVELOPMENT CREDENTIALS ONLY')
        ->expectsOutputToContain('CREATED')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'platform-admin@local.test')->firstOrFail();

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->tenantUsers()->count())->toBe(0)
        ->and($user->platformMfaCredential)->toBeNull()
        ->and(Hash::check('local-platform-admin-password', (string) $user->getRawOriginal('password')))->toBeTrue();

    $audit = DB::table('audit_logs')->where('action', 'platform.admin.local_provisioned')->first();
    expect($audit)->not->toBeNull()
        ->and((string) $audit->data)->not->toContain('local-platform-admin-password');
});

test('local command is idempotent and resets only the synthetic local account password', function (): void {
    $this->app->detectEnvironment(fn (): string => 'local');

    $user = User::factory()->unverified()->create([
        'name' => 'Old Local Name',
        'email' => 'platform-admin@local.test',
        'password' => 'old-password',
    ]);
    $user->tenantUsers()->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'role' => UserRole::Owner,
        'status' => TenantMembershipStatus::Active,
    ]);

    $this->artisan('local:platform-admin')
        ->expectsOutputToContain('UPDATED/ALREADY EXISTS')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->name)->toBe('Local Platform Admin')
        ->and($user->isSuperAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->tenantUsers()->count())->toBe(1)
        ->and($user->platformMfaCredential)->toBeNull()
        ->and(Hash::check('local-platform-admin-password', (string) $user->getRawOriginal('password')))->toBeTrue();
});

test('local command rejects non-local environments before touching the database', function (): void {
    $this->artisan('local:platform-admin')
        ->expectsOutputToContain('may only run in the local environment')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

test('local command rejects production without a bypass', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('local:platform-admin')
        ->expectsOutputToContain('may only run in the local environment')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});
