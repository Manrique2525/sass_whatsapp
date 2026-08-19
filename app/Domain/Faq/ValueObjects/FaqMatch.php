<?php

declare(strict_types=1);

namespace App\Domain\Faq\ValueObjects;

/**
 * Resultado de matching FAQ (FASE 18 U2, ADR-070).
 *
 * Value object inmutable retornado por FaqMatcherService.
 * Contiene únicamente lo necesario para el runtime futuro (U4).
 *
 * NO incluye: tenant_id, raw question, normalized question, PII,
 * Eloquent model, hit_count.
 */
final readonly class FaqMatch
{
    public function __construct(
        public string $faqId,
        public string $answer,
        public string $matchType,
        public int $priority,
    ) {}
}
