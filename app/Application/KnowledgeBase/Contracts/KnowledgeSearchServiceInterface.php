<?php

declare(strict_types=1);

namespace App\Application\KnowledgeBase\Contracts;

use App\Domain\KnowledgeBase\ValueObjects\KnowledgeSearchResult;

/**
 * Interfaz para búsqueda semántica knowledge (FASE 17 U3.4).
 *
 * Permite inyección de dependencias y testing con fakes.
 * Implementada por KnowledgeSearchService (producción) y FakeKnowledgeSearchService (tests).
 */
interface KnowledgeSearchServiceInterface
{
    public function search(
        string $tenantId,
        string $knowledgeBaseId,
        string $query,
        ?int $topK = null,
        ?float $threshold = null,
    ): KnowledgeSearchResult;
}
