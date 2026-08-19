<?php

declare(strict_types=1);

namespace App\Application\Faq\Services;

use App\Application\Faq\Contracts\FaqMatcherServiceInterface;
use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Models\Faq;
use App\Domain\Faq\ValueObjects\FaqMatch;
use App\Domain\Faq\ValueObjects\FaqQuestionNormalizer;
use App\Domain\Tenants\Models\Tenant;

/**
 * Matching FAQ determinista por normalización exacta (FASE 18 U2, ADR-070).
 *
 * Pipeline: normalize → query exact match → FaqMatch VO.
 *
 * READ ONLY: no escribe en DB, no incrementa contadores, no audita.
 * Reutiliza FaqQuestionNormalizer de U1 (sin duplicación).
 *
 * Tenant-scoped: la query filtra explícitamente por tenant_id (defense-in-depth).
 */
final class FaqMatcherService implements FaqMatcherServiceInterface
{
    public function __construct(
        private readonly FaqQuestionNormalizer $normalizer,
    ) {}

    public function match(Tenant $tenant, string $question): ?FaqMatch
    {
        $normalized = $this->normalizer->normalize($question);

        if ($normalized === '') {
            return null;
        }

        $faq = Faq::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', FaqStatus::Active)
            ->where('normalized_question', $normalized)
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($faq === null) {
            return null;
        }

        return new FaqMatch(
            faqId: $faq->id,
            answer: $faq->answer,
            matchType: 'exact_normalized',
            priority: $faq->priority,
        );
    }
}
