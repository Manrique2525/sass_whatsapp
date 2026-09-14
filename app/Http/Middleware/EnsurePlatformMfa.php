<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Users\Models\PlatformMfaCredential;
use App\Domain\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('platform/security*')) {
            return $next($request);
        }
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        /** @var PlatformMfaCredential|null $credential */
        $credential = $user->platformMfaCredential;
        if (! $credential?->isEnabled()) {
            return redirect()->route('platform.security');
        }
        $verifiedAt = $request->session()->get('platform_mfa_verified_at');
        $verified = $request->session()->get('platform_mfa_verified_user_id') === $user->id
            && is_int($verifiedAt)
            && Carbon::createFromTimestamp($verifiedAt)->greaterThan(now()->subMinutes(15));

        if (! $verified) {
            return redirect()->route('platform.security.challenge');
        }

        return $next($request);
    }
}
