<?php

declare(strict_types=1);

namespace App\Application\Faq\Contracts;

use App\Domain\Faq\ValueObjects\FaqMatch;
use App\Domain\Tenants\Models\Tenant;

/**
 * Interfaz para matching FAQ (FASE 18 U2, ADR-070).
 *
 * Permite inyección de dependencias y testing con fakes.
 * Implementada por FaqMatcherService (producción) y FakeFaqMatcherService (tests U4).
 */
interface FaqMatcherServiceInterface
{
    /**
     * Busca una FAQ que matchee exactamente la pregunta normalizada.
     *
     * @return FaqMatch|null null si no hay match o input vacío
     */
    public function match(Tenant $tenant, string $question): ?FaqMatch;
}
