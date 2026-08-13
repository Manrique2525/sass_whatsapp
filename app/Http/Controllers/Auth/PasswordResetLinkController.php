<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Users\Services\SendPasswordResetLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(ForgotPasswordRequest $request, SendPasswordResetLink $sendPasswordResetLink): RedirectResponse
    {
        $sendPasswordResetLink->send($request->validated('email'));

        return back()->with('status', 'Te hemos enviado por email el enlace para restablecer tu contraseña.');
    }
}
