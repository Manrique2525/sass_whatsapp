<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Faq\Contracts\FaqMatcherServiceInterface;
use App\Domain\Faq\ValueObjects\FaqMatch;
use App\Domain\Tenants\Models\Tenant;

/**
 * Test double para FaqMatcherServiceInterface (FASE 18 U4).
 *
 * Permite configurar el resultado del match, contar llamadas y
 * capturar los parámetros para assertions. No usa Eloquent ni DB.
 */
final class FakeFaqMatcherService implements FaqMatcherServiceInterface
{
    private ?FaqMatch $matchResult = null;

    private int $matchCount = 0;

    private ?Tenant $lastTenant = null;

    private ?string $lastQuestion = null;

    private ?\Throwable $throwOnMatch = null;

    public function match(Tenant $tenant, string $question): ?FaqMatch
    {
        $this->matchCount++;
        $this->lastTenant = $tenant;
        $this->lastQuestion = $question;

        if ($this->throwOnMatch !== null) {
            throw $this->throwOnMatch;
        }

        return $this->matchResult;
    }

    /**
     * Configura el resultado que match() devolverá en la siguiente llamada.
     */
    public function whenMatch(?FaqMatch $result): static
    {
        $this->matchResult = $result;

        return $this;
    }

    /**
     * Configura una excepción que match() lanzará en la siguiente llamada.
     */
    public function whenThrow(\Throwable $exception): static
    {
        $this->throwOnMatch = $exception;

        return $this;
    }

    public function matchCount(): int
    {
        return $this->matchCount;
    }

    public function lastTenant(): ?Tenant
    {
        return $this->lastTenant;
    }

    public function lastQuestion(): ?string
    {
        return $this->lastQuestion;
    }

    public function reset(): void
    {
        $this->matchResult = null;
        $this->matchCount = 0;
        $this->lastTenant = null;
        $this->lastQuestion = null;
        $this->throwOnMatch = null;
    }
}
