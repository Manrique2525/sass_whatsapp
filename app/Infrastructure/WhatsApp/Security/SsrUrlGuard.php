<?php

declare(strict_types=1);

namespace App\Infrastructure\WhatsApp\Security;

use App\Domain\Messages\Enums\MessageMediaFailureReason;
use App\Domain\WhatsApp\Exceptions\WhatsAppMediaDownloadException;

/**
 * Validador SSRF para URLs de descarga de media (FASE 31 U5, ADR-121).
 *
 * Garantiza que la descarga de un media de Meta jamás alcance hosts privados
 * (loopback, RFC1918, link-local, metadata cloud) ni IPs huerfanas de servicio.
 * El único origen legítimo de la URL es el look-up de Meta; este guard se aplica
 * sobre la URL devuelta y sobre cada hop de redirección.
 *
 * La resolución DNS es inyectable para poder testear con seguridad el
 * comportamiento (un hostname que resuelve a una IP privada debe rechazarse).
 */
final class SsrUrlGuard
{
    /**
     * @param  (\Closure(string): list<string>)|null  $resolver
     */
    public function __construct(
        private readonly ?\Closure $resolver = null,
        private readonly bool $requireHttps = true,
    ) {}

    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new WhatsAppMediaDownloadException(
                'URL de media inválida.',
                MessageMediaFailureReason::SsrfRejected,
            );
        }

        if ($this->requireHttps) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($scheme !== 'https') {
                throw new WhatsAppMediaDownloadException(
                    'Solo se permiten URLs https para media.',
                    MessageMediaFailureReason::SsrfRejected,
                );
            }
        }

        $host = (string) ($parts['host'] ?? '');

        if ($host === '') {
            throw new WhatsAppMediaDownloadException(
                'URL de media sin host.',
                MessageMediaFailureReason::SsrfRejected,
            );
        }

        $this->assertHostSafe($host);
    }

    private function assertHostSafe(string $host): void
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip !== false) {
            $this->assertIpSafe($ip);
        }

        $ipAddresses = $this->resolve($host);

        if ($ipAddresses === []) {
            // Resolución fallida: fallo seguro.
            throw new WhatsAppMediaDownloadException(
                'No se pudo resolver el host de media.',
                MessageMediaFailureReason::SsrfRejected,
            );
        }

        foreach ($ipAddresses as $address) {
            $this->assertIpSafe($address);
        }
    }

    private function assertIpSafe(string $ip): void
    {
        if (! $this->isSafeIp($ip)) {
            throw new WhatsAppMediaDownloadException(
                'Host de media en rango bloqueado.',
                MessageMediaFailureReason::SsrfRejected,
            );
        }
    }

    private function isSafeIp(string $ip): bool
    {
        $blocked = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || $this->isLoopback($ip)
            || $this->isLinkLocal($ip)
            || $this->isMulticast($ip)
            || $this->isUnspecified($ip);

        return ! $blocked;
    }

    private function isLoopback(string $ip): bool
    {
        $inet = inet_pton($ip);

        if ($inet === false) {
            return false;
        }

        $null = str_repeat("\0", 15)."\x01";

        return $ip === '127.0.0.1' || $inet === $null;
    }

    private function isLinkLocal(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            $expanded = inet_pton($ip);

            return $expanded !== false && str_starts_with(bin2hex($expanded), 'fe80');
        }

        $first = (int) explode('.', $ip)[0];

        return $first === 169;
    }

    private function isMulticast(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            $first = strtolower(substr($ip, 0, 3));

            return str_starts_with($first, 'ff');
        }

        $first = (int) explode('.', $ip)[0];

        return $first >= 224 && $first <= 239;
    }

    private function isUnspecified(string $ip): bool
    {
        return $ip === '0.0.0.0' || trim($ip, ':') === '';
    }

    /**
     * Resuelve un hostname a una lista de direcciones IP.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $records = @gethostbynamel($host);

        if ($records !== []) {
            return $records;
        }

        $single = @gethostbyname($host);

        if (filter_var($single, FILTER_VALIDATE_IP) !== false && strtolower($single) !== strtolower($host)) {
            return [$single];
        }

        return [];
    }
}
