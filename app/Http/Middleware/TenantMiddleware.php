<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica el contexto de tenant al request autenticado.
 *
 * - Resuelve el tenant activo del usuario (`current_tenant_id`).
 * - Valida SIEMPRE la pertenencia contra `tenant_users` (un `current_tenant_id`
 *   obsoleto o ajeno es rechazado).
 * - Fija `TenantContext` antes de ejecutar y lo libera en `finally` (incluso si
 *   el handler lanza una excepción).
 * - Sin tenant activo/válido: 403 (JSON `NO_TENANT` para API, abort para web).
 */
final class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->deny($request);
        }

        $tenant = $user->currentTenant;

        if ($tenant === null || ! $tenant->isActive() || ! $user->belongsToTenant($tenant)) {
            return $this->deny($request);
        }

        TenantContext::set($tenant);

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }

    private function deny(Request $request): Response
    {
        if ($request->is('api/*')) {
            return response()->json([
                'message' => 'Sin tenant activo.',
                'code' => 'NO_TENANT',
            ], 403);
        }

        abort(403);
    }
}
