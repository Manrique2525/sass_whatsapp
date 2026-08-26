<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that binds request_id and tenant_id to the Sentry scope
 * for every HTTP request. This ensures all Sentry events (exceptions,
 * errors) from this request include these diagnostic tags.
 *
 * Placed after RequestCorrelationId so request_id is already resolved.
 */
final class SentryScopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->attributes->get('request_id');

        \Sentry\configureScope(function (Scope $scope) use ($requestId): void {
            if ($requestId !== null) {
                $scope->setTag('request_id', (string) $requestId);
            }

            $tenantId = TenantContext::id();
            if ($tenantId !== null) {
                $scope->setTag('tenant_id', (string) $tenantId);
            }
        });

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
