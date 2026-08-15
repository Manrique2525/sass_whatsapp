<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Flows\Exceptions\WebhookUrlBlockedException;

/**
 * Guard anti-SSRF para nodos `webhook` (FASE 11, seguridad FLOW-22).
 *
 * Prohibe hosts locales y direcciones privadas/reservadas antes de realizar la
 * petición: resuelve el DNS del host y verifica cada IP. La defensa es
 * doble: `withoutRedirecting()` en el cliente HTTP evita redirecciones a
 * destinos internos. No se realiza ningún request si el URL no pasa el guard.
 */
final class WebhookUrlGuard
{
    public function assertAllowed(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new WebhookUrlBlockedException('El URL del webhook solo admite http(s).');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new WebhookUrlBlockedException('El URL del webhook no tiene un host válido.');
        }

        $host = strtolower($host);

        if ($this->isLocalName($host)) {
            throw new WebhookUrlBlockedException("El host '{$host}' está bloqueado (SSRF).");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isPrivateOrReserved($host)) {
                throw new WebhookUrlBlockedException("La IP '{$host}' está bloqueada (SSRF).");
            }

            return;
        }

        $ips = gethostbynamel($host);

        if ($ips === false || $ips === []) {
            throw new WebhookUrlBlockedException("No se pudo resolver el host '{$host}'.");
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateOrReserved($ip)) {
                throw new WebhookUrlBlockedException("El host '{$host}' resuelve a una IP bloqueada '{$ip}' (SSRF).");
            }
        }
    }

    /**
     * Versión del URL segura para logs/auditoría/errores (FASE 13, UNIDAD 5):
     * elimina userinfo (credenciales embebidas), query y fragment. La URL
     * literal de la config puede contener query con tokens/API keys; jamás
     * debe terminar en `flow_execution_logs`, auditoría o mensajes de error.
     */
    public static function sanitizeForLog(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return '(url inválida)';
        }

        $host = $parts['host'];

        if (isset($parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        return $parts['scheme'].'://'.$host.($parts['path'] ?? '');
    }

    private function isLocalName(string $host): bool
    {
        return $host === 'localhost'
            || $host === 'localhost.localdomain'
            || str_ends_with($host, '.localhost')
            || $host === '0.0.0.0'
            || $host === '::1'
            || str_starts_with($host, '127.')
            || str_starts_with($host, '[::1]');
    }

    private function isPrivateOrReserved(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
