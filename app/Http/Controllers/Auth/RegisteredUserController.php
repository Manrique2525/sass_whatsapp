<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Users\Services\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request, RegisterUser $registerUser): RedirectResponse
    {
        $user = $registerUser->register(
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('password'),
        );

        $user->sendEmailVerificationNotification();

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
