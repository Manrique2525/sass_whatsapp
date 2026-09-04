<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Users\Services\VerifyUserEmail;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, VerifyUserEmail $verifyUserEmail): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $verifyUserEmail->verify($user);
        }

        // Tras verificar el email, el usuario recién provisionado entra al
        // onboarding (workspace + plan free creados en el signup, ADR-124).
        return redirect()->route('onboarding')->with('status', 'Tu email ha sido verificado.');
    }
}
