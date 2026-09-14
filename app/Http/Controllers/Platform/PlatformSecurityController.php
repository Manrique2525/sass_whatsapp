<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Application\Platform\Services\PlatformMfaService;
use App\Domain\Users\Models\PlatformMfaCredential;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ConfirmPlatformMfaEnrollmentRequest;
use App\Http\Requests\Platform\PlatformMfaActionRequest;
use App\Http\Requests\Platform\PlatformMfaChallengeRequest;
use App\Http\Requests\Platform\StartPlatformMfaEnrollmentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use OTPHP\TOTP;

final class PlatformSecurityController extends Controller
{
    public function __construct(private readonly PlatformMfaService $mfa) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $pendingId = $request->session()->get('platform_mfa_pending_credential_id');
        /** @var PlatformMfaCredential|null $pendingCredential */
        $pendingCredential = is_string($pendingId)
            ? PlatformMfaCredential::query()->where('user_id', $user->id)->whereKey($pendingId)->whereNull('enabled_at')->first()
            : null;
        $enrollment = $pendingCredential === null ? null : [
            'secret' => (string) $pendingCredential->secret,
            'uri' => $this->uri((string) $pendingCredential->secret, $user),
        ];
        /** @var PlatformMfaCredential|null $credential */
        $credential = $user->platformMfaCredential;

        return Inertia::render('Platform/Security', ['enabled' => $credential?->isEnabled() === true, 'enrollment' => $enrollment]);
    }

    public function start(StartPlatformMfaEnrollmentRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->mfa->assertCurrentPassword($user, $request->validated('password'));
        $this->mfa->beginEnrollment($user);
        $request->session()->put('platform_mfa_pending_credential_id', PlatformMfaCredential::query()->where('user_id', $user->id)->value('id'));

        return to_route('platform.security');
    }

    public function confirm(ConfirmPlatformMfaEnrollmentRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $codes = $this->mfa->confirmEnrollment($user, $request->validated('code'));
        $request->session()->forget('platform_mfa_pending_credential_id');
        $request->session()->put('platform_mfa_verified_user_id', $user->id);
        $request->session()->put('platform_mfa_verified_at', now()->timestamp);

        return to_route('platform.security')->with('recovery_codes', $codes);
    }

    public function challenge(PlatformMfaChallengeRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $code = $request->validated('code');
        if (! $this->mfa->verifyTotp($user, $code) && ! $this->mfa->consumeRecoveryCode($user, $code)) {
            throw ValidationException::withMessages(['code' => 'The MFA code is invalid.']);
        }
        $request->session()->put('platform_mfa_verified_user_id', $user->id);
        $request->session()->put('platform_mfa_verified_at', now()->timestamp);

        return to_route('platform.dashboard');
    }

    public function disable(PlatformMfaActionRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->mfa->disable($user, $request->validated('password'), $request->validated('factor'));
        $request->session()->forget(['platform_mfa_verified_user_id', 'platform_mfa_verified_at']);

        return to_route('platform.security');
    }

    public function regenerate(PlatformMfaActionRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $codes = $this->mfa->regenerate($user, $request->validated('password'), $request->validated('factor'));

        return to_route('platform.security')->with('recovery_codes', $codes);
    }

    private function uri(string $secret, User $user): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setPeriod(30);
        $totp->setDigits(6);
        $totp->setDigest('sha1');
        $totp->setIssuer('WhatsApp SaaS');
        $totp->setLabel($user->email);

        return $totp->getProvisioningUri();
    }
}
