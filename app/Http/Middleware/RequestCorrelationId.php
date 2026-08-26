<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que garantiza que cada request tenga un request_id único y seguro.
 *
 * - Lee X-Request-ID del incoming request (si es UUID válido, lo preserva).
 * - Si no existe o es inválido, genera uno nuevo (UUID v4).
 * - Almacena en Request::attributes para uso posterior (log context, response header).
 * - Retorna X-Request-ID en la response.
 * - Almacena en Monolog shared context para que todos los log lines lo incluyan.
 */
final class RequestCorrelationId
{
    private const HEADER = 'X-Request-ID';

    private const MAX_LENGTH = 128;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
        ]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = $request->header(self::HEADER);

        if ($incoming !== null && $this->isValidRequestId($incoming)) {
            return $incoming;
        }

        return Str::uuid()->toString();
    }

    private function isValidRequestId(string $value): bool
    {
        if (strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        // Accept UUID format or safe alphanumeric/hyphen/underscore identifiers
        return (bool) preg_match('/^[a-zA-Z0-9\-_]{1,'.self::MAX_LENGTH.'}$/', $value);
    }
}
