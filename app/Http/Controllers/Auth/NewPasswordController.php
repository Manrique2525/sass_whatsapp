<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Users\Services\ResetUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $request->query('token', ''),
            'email' => $request->query('email', ''),
        ]);
    }

    public function store(ResetPasswordRequest $request, ResetUserPassword $resetUserPassword): RedirectResponse
    {
        $status = $resetUserPassword->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
            $request->validated('password_confirmation'),
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => trans($status),
            ]);
        }

        return redirect()->route('login')->with('status', trans($status));
    }
}
