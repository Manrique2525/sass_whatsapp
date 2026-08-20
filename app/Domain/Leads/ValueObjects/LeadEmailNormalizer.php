<?php

declare(strict_types=1);

namespace App\Domain\Leads\ValueObjects;

/**
 * Normalizador canónico de emails para leads (FASE 19, ADR-072).
 *
 * Contrato de normalización:
 * - trim
 * - Unicode lowercase (mb_strtolower UTF-8)
 *
 * NO hace:
 * - strip plus addressing (algunos sistemas tratan +tag como significativo)
 * - modificación artificial del dominio
 * - equivalencias provider-specific (Gmail dots, etc.)
 * - validación RFC (eso pertenece a U2 service/request)
 *
 * Ejemples:
 *   ' Juan@Example.COM ' → 'juan@example.com'
 *   'USER+TAG@DOMAIN.ORG' → 'user+tag@domain.org'
 */
final class LeadEmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }
}
