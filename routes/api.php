<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');

    Route::post('auth/forgot-password', [PasswordController::class, 'forgot'])
        ->middleware('throttle:auth-password');

    Route::post('auth/reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:auth-password');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });
});
