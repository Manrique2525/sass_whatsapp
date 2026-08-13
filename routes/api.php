<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\TenantController;
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

        Route::prefix('tenants')->group(function (): void {
            Route::get('/', [TenantController::class, 'index'])
                ->middleware('can:viewAny,'.Tenant::class);

            // La autorización efectiva la aplican los Application Services +
            // controller: no-miembro/no-activo -> 404; suspendido -> 409
            // (ocultar existencia, ver ADR-010/023). Las policies quedan como
            // capa programática (authorize()) y para el index.
            Route::post('{tenant}/switch', [TenantController::class, 'switch']);

            // Recursos del tenant bajo contexto `tenant`.
            Route::middleware('tenant')->group(function (): void {
                Route::get('{tenant}', [TenantController::class, 'show']);

                Route::put('{tenant}', [TenantController::class, 'update']);
            });
        });
    });
});
