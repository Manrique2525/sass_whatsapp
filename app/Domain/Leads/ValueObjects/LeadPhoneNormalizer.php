<?php

declare(strict_types=1);

namespace App\Domain\Leads\ValueObjects;

/**
 * Normalizador canónico de teléfonos para leads (FASE 19, ADR-072).
 *
 * Reutiliza el contrato de normalización de ContactService::normalizePhone():
 * - trim
 * - eliminar todos los caracteres que no son dígitos
 * - prefijo "+"
 *
 * Esto produce una representación canónica estilo internacional,
 * NO validación E.164 completa (E.164 implica restricciones de
 * longitud y prefijo que este normalizador no verifica).
 *
 * Ejemplos:
 *   '+52 993 123 4567' → '+529931234567'
 *   '5491155554444'     → '+549115554444'
 *   '(11) 5555-4444'    → '+1155554444'
 *   ''                  → ''
 */
final class LeadPhoneNormalizer
{
    /**
     * Normaliza un teléfono a representación canónica.
     *
     * Cadena vacía o solo espacios → cadena vacía.
     * El caller (service/U2) decidirá convertir '' a null antes de persistir.
     */
    public function normalize(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        if ($digits === '') {
            return '';
        }

        return '+'.$digits;
    }
}
