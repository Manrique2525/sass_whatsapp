<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

/**
 * Utility para sanitizar datos sensibles antes de enviarlos a logs.
 *
 * Regla: los raw messages de proveedores externos pueden contener
 * tokens, phone numbers, prompts, PII, o payloads completos.
 * Se truncan y renombran para preservar diagnostic value sin riesgo de privacidad.
 */
final class SafeLogContext
{
    private const MAX_RAW_LENGTH = 200;

    /**
     * Sanitiza un raw message de proveedor para log seguro.
     *
     * Trunca, remueve patterns sensibles, y retorna string seguro.
     */
    public static function sanitizeProviderMessage(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return 'N/A';
        }

        $sanitized = $raw;

        // Remove potential tokens (strings starting with sk-, sk_live_, sk_test_, Bearer, etc.)
        $sanitized = preg_replace('/sk[-_](?:live|test|proj)?[a-zA-Z0-9\-_]{10,}/', '[REDACTED]', $sanitized);
        $sanitized = preg_replace('/Bearer\s+[a-zA-Z0-9\-_\.]{20,}/', 'Bearer [REDACTED]', $sanitized);

        // Remove potential phone numbers (E.164 format)
        $sanitized = preg_replace('/\+[1-9]\d{6,14}/', '[PHONE]', $sanitized);

        // Remove potential email addresses
        $sanitized = preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $sanitized);

        // Truncate
        if (strlen($sanitized) > self::MAX_RAW_LENGTH) {
            $sanitized = substr($sanitized, 0, self::MAX_RAW_LENGTH).'...';
        }

        return $sanitized;
    }
}
