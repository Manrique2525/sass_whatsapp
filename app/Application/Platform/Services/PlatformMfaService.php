<?php

declare(strict_types=1);

namespace App\Application\Platform\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Users\Models\PlatformMfaCredential;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\TOTP;

final class PlatformMfaService
{
    private const PERIOD = 30;

    // OTPHP requires leeway to be strictly below the 30-second period; the
    // boundary timestamps are checked explicitly below.
    private const LEEWAY = 30;

    private const RECOVERY_COUNT = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{secret: string, uri: string} */
    public function beginEnrollment(User $user): array
    {
        $totp = TOTP::generate(null, 20);
        $totp->setPeriod(self::PERIOD);
        $totp->setDigits(6);
        $totp->setDigest('sha1');
        $totp->setIssuer('WhatsApp SaaS');
        $totp->setLabel($user->email);

        PlatformMfaCredential::query()->updateOrCreate(['user_id' => $user->id], [
            'secret' => $totp->getSecret(),
            'recovery_codes' => [],
            'enabled_at' => null,
            'recovery_codes_generated_at' => null,
        ]);

        return ['secret' => $totp->getSecret(), 'uri' => $totp->getProvisioningUri()];
    }

    /** @return list<string> Plaintext codes, returned only to the caller once. */
    public function confirmEnrollment(User $user, string $code): array
    {
        /** @var PlatformMfaCredential|null $credential */
        $credential = PlatformMfaCredential::query()->where('user_id', $user->id)->first();
        abort_unless($credential !== null && ! $credential->isEnabled(), 422, 'Start MFA enrollment first.');
        $this->assertTotp((string) $credential->secret, $code);
        $codes = array_map(static fn (): string => Str::upper(Str::random(12)), range(1, self::RECOVERY_COUNT));

        $credential->update([
            'recovery_codes' => array_map(static fn (string $code): string => Hash::make($code), $codes),
            'enabled_at' => now(),
            'recovery_codes_generated_at' => now(),
        ]);
        $this->audit->record('platform.mfa.enabled', ['recovery_codes_count' => count($codes)], PlatformMfaCredential::class, $credential->id, platform: true);

        return $codes;
    }

    public function verifyTotp(User $user, string $code): bool
    {
        /** @var PlatformMfaCredential|null $credential */
        $credential = PlatformMfaCredential::query()->where('user_id', $user->id)->first();

        return $credential?->isEnabled() === true && $this->isValidTotp((string) $credential->secret, $code);
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $credential = PlatformMfaCredential::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($credential === null || ! $credential->isEnabled()) {
                return false;
            }
            /** @var array<int, string> $codes */
            $codes = (array) $credential->recovery_codes;
            foreach ($codes as $index => $hash) {
                if (Hash::check($code, (string) $hash)) {
                    unset($codes[$index]);
                    $credential->update(['recovery_codes' => array_values($codes)]);
                    $this->audit->record('platform.mfa.recovery_used', [], PlatformMfaCredential::class, $credential->id, platform: true);

                    return true;
                }
            }

            return false;
        });
    }

    /** @return list<string> */
    public function regenerate(User $user, string $password, string $factor): array
    {
        $this->assertPassword($user, $password);
        abort_unless($this->verifyFactor($user, $factor), 422, 'A valid TOTP or recovery code is required.');

        return DB::transaction(function () use ($user): array {
            $credential = PlatformMfaCredential::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $codes = array_map(static fn (): string => Str::upper(Str::random(12)), range(1, self::RECOVERY_COUNT));
            $credential->update(['recovery_codes' => array_map(static fn (string $code): string => Hash::make($code), $codes), 'recovery_codes_generated_at' => now()]);
            $this->audit->record('platform.mfa.recovery_codes_regenerated', ['recovery_codes_count' => count($codes)], PlatformMfaCredential::class, $credential->id, platform: true);

            return $codes;
        });
    }

    public function disable(User $user, string $password, string $factor): void
    {
        $this->assertPassword($user, $password);
        abort_unless($this->verifyFactor($user, $factor), 422, 'A valid TOTP or recovery code is required.');
        PlatformMfaCredential::query()->where('user_id', $user->id)->delete();
        $this->audit->record('platform.mfa.disabled', [], PlatformMfaCredential::class, null, platform: true);
    }

    private function verifyFactor(User $user, string $factor): bool
    {
        return $this->verifyTotp($user, $factor) || $this->consumeRecoveryCode($user, $factor);
    }

    private function assertPassword(User $user, string $password): void
    {
        abort_unless(Hash::check($password, (string) $user->password), 422, 'The current password is invalid.');
    }

    public function assertCurrentPassword(User $user, string $password): void
    {
        $this->assertPassword($user, $password);
    }

    private function assertTotp(string $secret, string $code): void
    {
        abort_unless($this->isValidTotp($secret, $code), 422, 'The authenticator code is invalid.');
    }

    private function isValidTotp(string $secret, string $code): bool
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(self::PERIOD);
        $totp->setDigits(6);
        $totp->setDigest('sha1');

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $timestamp = time();

        return $totp->verify($code, $timestamp, self::LEEWAY - 1)
            || $totp->verify($code, $timestamp - self::LEEWAY, 0)
            || $totp->verify($code, $timestamp + self::LEEWAY, 0);
    }
}
