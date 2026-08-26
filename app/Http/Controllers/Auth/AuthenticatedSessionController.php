<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthenticateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(
        LoginRequest $request,
        AuthenticateUser $authenticateUser,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $user = $authenticateUser->authenticate(
            $request->validated('email'),
            $request->validated('password'),
        );

        if ($user === null) {
            $auditLogger->record(
                action: 'user.login_failed',
                data: ['reason' => 'invalid_credentials'],
            );

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
