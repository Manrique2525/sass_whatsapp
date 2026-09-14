<?php

declare(strict_types=1);

use App\Application\Platform\Services\PlatformMfaService;
use App\Application\Users\Services\TenantRoleManager;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OTPHP\TOTP;

uses(RefreshDatabase::class);

function mfa_platform_admin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['password' => 'password123']);
    app(TenantRoleManager::class)->assignGlobalRole($user, UserRole::SuperAdmin);

    return $user->fresh();
}

test('platform without MFA redirects to security and enrollment only completes with valid TOTP', function (): void {
    $user = mfa_platform_admin();
    $this->actingAs($user)->get('/platform')->assertRedirect('/platform/security');
    $this->actingAs($user)->post('/platform/security/enroll', ['password' => 'password123'])->assertRedirect('/platform/security');
    $secret = $user->fresh()->platformMfaCredential?->secret;
    $totp = TOTP::createFromSecret($secret);
    $totp->setPeriod(30);
    $totp->setDigits(6);
    $totp->setDigest('sha1');
    $this->post('/platform/security/enroll/confirm', ['code' => $totp->now()])->assertRedirect('/platform/security');
    expect($user->fresh()->platformMfaCredential?->isEnabled())->toBeTrue()
        ->and(session('recovery_codes'))->toHaveCount(10);
});

test('challenge accepts TOTP, rejects remembered session state, and logout clears assertion', function (): void {
    $user = mfa_platform_admin();
    $service = app(PlatformMfaService::class);
    $enrollment = $service->beginEnrollment($user);
    $totp = TOTP::createFromSecret($enrollment['secret']);
    $service->confirmEnrollment($user, $totp->now());
    $this->actingAs($user)->withSession(['platform_mfa_verified_user_id' => $user->id, 'platform_mfa_verified_at' => now()->timestamp])->get('/platform')->assertOk();
    $this->withSession(['platform_mfa_verified_user_id' => null, 'platform_mfa_verified_at' => null]);
    $this->actingAs($user)->get('/platform')->assertRedirect('/platform/security/challenge');
    $this->post('/platform/security/challenge', ['code' => $totp->now()])->assertRedirect('/platform/dashboard');
    $this->post('/logout')->assertRedirect('/');
    expect(session('platform_mfa_verified_user_id'))->toBeNull()->and(session('platform_mfa_verified_at'))->toBeNull();
});
