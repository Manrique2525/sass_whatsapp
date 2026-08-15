<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

/**
 * Guard de variables del motor de flujos (FASE 13, UNIDAD 1).
 *
 * Política de claves y valores de variables:
 * - Claves snake_case estrictas: `^[a-z][a-z0-9_]*$`, longitud máxima 64.
 *   FIX C8: el regex original de FASE 11 (`/^[a-z][a-z0-9_]*$/i`) aceptaba
 *   mayúsculas; aquí se elimina el modificador `i`.
 * - Prohibidas las claves que puedan colisionar con la runtime del frontend o
 *   permitir prototype pollution: `__proto__`, `constructor`, `prototype` y
 *   cualquier clave con `__`.
 * - `metadata` de contacto se escribe SOLO de forma explícita y por clave
 *   válida; los campos de columna del contacto (id, name, email, phone, ...)
 *   NUNCA son escribibles desde el contacto.
 * - Los valores capturados se recortan y se limitan en longitud
 *   (`MAX_VALUE_LENGTH`).
 */
final class VariableGuard
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public const MAX_KEY_LENGTH = 64;

    public const MAX_VALUE_LENGTH = 4096;

    /**
     * Campos de contacto protegidos: el contacto nunca puede escribirlos.
     *
     * @var list<string>
     */
    public const PROTECTED_CONTACT_FIELDS = [
        'id',
        'tenant_id',
        'phone',
        'provider_contact_id',
        'created_at',
        'updated_at',
        'name',
        'email',
    ];

    /**
     * Claves peligrosas: colisionan con la runtime del frontend o permiten
     * prototype pollution si se interpola sin control.
     *
     * @var list<string>
     */
    private const DANGEROUS_EXACT_KEYS = ['constructor', 'prototype'];

    public static function isValidKey(string $key): bool
    {
        if ($key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            return false;
        }

        return preg_match(self::KEY_PATTERN, $key) === 1 && ! self::isDangerousKey($key);
    }

    public static function isDangerousKey(string $key): bool
    {
        return str_contains($key, '__') || in_array($key, self::DANGEROUS_EXACT_KEYS, true);
    }

    /**
     * Normaliza una clave a snake_case estricto (trim + minúsculas). FIX C8:
     * el motor normaliza a minúsculas la clave capturada por defensa en
     * profundidad, aunque el validador ya rechaza las claves no válidas.
     */
    public static function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * Única forma válida de escribir datos del contacto desde el motor: una
     * subclave `metadata.<clave>`, donde la clave es válida y no está entre
     * los campos protegidos. Cualquier campo de columna (name, email, phone,
     * id, ...) queda fuera.
     */
    public static function isWritableContactField(string $key): bool
    {
        if (! str_starts_with($key, 'metadata.')) {
            return false;
        }

        $subKey = substr($key, strlen('metadata.'));

        if ($subKey === '' || in_array($subKey, self::PROTECTED_CONTACT_FIELDS, true)) {
            return false;
        }

        return self::isValidKey($subKey);
    }

    /**
     * Recorta el valor capturado a `MAX_VALUE_LENGTH` caracteres (multibyte).
     */
    public static function truncateValue(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_VALUE_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
    }
}
