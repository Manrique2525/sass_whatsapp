<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Tenants\Services\ProvisionNewWorkspace;
use App\Application\Users\Services\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(
        RegisterRequest $request,
        RegisterUser $registerUser,
        ProvisionNewWorkspace $provisionNewWorkspace,
    ): RedirectResponse {
        // Alta de usuario + provisionado del workspace en una única transacción
        // atómica: si falla una parte crítica se revierte todo (ADR-124).
        $user = DB::transaction(function () use ($registerUser, $request, $provisionNewWorkspace) {
            $created = $registerUser->register(
                $request->validated('name'),
                $request->validated('email'),
                $request->validated('password'),
            );

            $provisionNewWorkspace->provision($created);

            return $created;
        });

        $user->sendEmailVerificationNotification();

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
