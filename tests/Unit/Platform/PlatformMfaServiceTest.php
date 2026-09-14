<?php

declare(strict_types=1);

use App\Application\Platform\Services\PlatformMfaService;
use App\Domain\Users\Models\PlatformMfaCredential;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;

uses(RefreshDatabase::class);

function platform_totp(string $secret): TOTP
{
    $totp = TOTP::createFromSecret($secret);
    $totp->setPeriod(30);
    $totp->setDigits(6);
    $totp->setDigest('sha1');

    return $totp;
}

test('platform MFA enrollment is pending until a valid TOTP and encrypts its secret', function (): void {
    $user = User::factory()->create();
    $service = app(PlatformMfaService::class);
    $enrollment = $service->beginEnrollment($user);

    expect(PlatformMfaCredential::query()->count())->toBe(1);
    $codes = $service->confirmEnrollment($user, platform_totp($enrollment['secret'])->now());
    $credential = PlatformMfaCredential::query()->firstOrFail();

    expect($codes)->toHaveCount(10)
        ->and($credential->secret)->toBe($enrollment['secret'])
        ->and($credential->getRawOriginal('secret'))->not->toBe($enrollment['secret'])
        ->and($credential->recovery_codes)->each->toBeString()->and($credential->enabled_at)->not->toBeNull();
    expect(Hash::check($codes[0], $credential->recovery_codes[0]))->toBeTrue();
});

test('valid and invalid TOTP and recovery codes are handled once', function (): void {
    $user = User::factory()->create();
    $service = app(PlatformMfaService::class);
    $enrollment = $service->beginEnrollment($user);
    $codes = $service->confirmEnrollment($user, platform_totp($enrollment['secret'])->now());

    expect($service->verifyTotp($user->fresh(), platform_totp($enrollment['secret'])->now()))->toBeTrue()
        ->and($service->verifyTotp($user->fresh(), '000000'))->toBeFalse()
        ->and($service->consumeRecoveryCode($user, $codes[0]))->toBeTrue()
        ->and($service->consumeRecoveryCode($user->fresh(), $codes[0]))->toBeFalse();
});
