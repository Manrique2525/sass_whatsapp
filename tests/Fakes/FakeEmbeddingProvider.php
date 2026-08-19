<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\ValueObjects\EmbeddingRequest;
use App\Domain\AI\ValueObjects\EmbeddingResponse;
use Closure;

/**
 * Fake del EmbeddingProviderInterface para tests (FASE 17 U3.1).
 *
 * Permite configurar respuestas, excepciones y contar llamadas
 * sin acoplar los tests a OpenAI ni a Http::fake.
 * Determinístico y reproducible.
 */
final class FakeEmbeddingProvider implements EmbeddingProviderInterface
{
    private int $callCount = 0;

    private int $dimension = 1536;

    private ?\Throwable $exception = null;

    private bool $returnWrongDimension = false;

    private int $wrongDimension = 100;

    /** @var list<EmbeddingRequest> */
    private array $capturedRequests = [];

    private ?Closure $onCall = null;

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->callCount++;
        $this->capturedRequests[] = $request;

        if ($this->onCall !== null) {
            ($this->onCall)($request);
        }

        if ($this->exception !== null) {
            throw $this->exception;
        }

        $embeddings = [];

        foreach ($request->input as $text) {
            if ($this->returnWrongDimension) {
                $embeddings[] = array_fill(0, $this->wrongDimension, 0.1);
            } else {
                $embeddings[] = $this->deterministicVector($text);
            }
        }

        return new EmbeddingResponse(
            embeddings: $embeddings,
            provider: 'fake',
            model: 'fake-embedding-model',
            totalInputTokens: count($request->input) * 10,
        );
    }

    /**
     * Genera un vector determinístico normalizado a partir del hash del texto.
     *
     * @return list<float>
     */
    private function deterministicVector(string $text): array
    {
        $hash = md5($text);
        $vector = [];

        for ($i = 0; $i < $this->dimension; $i++) {
            $byte = ord($hash[$i % 32]);
            $vector[] = ($byte / 255.0) * 2 - 1;
        }

        $norm = sqrt(array_reduce($vector, fn (float $sum, float $v): float => $sum + $v * $v, 0.0));

        if ($norm > 0.0) {
            $vector = array_map(fn (float $v): float => $v / $norm, $vector);
        }

        return $vector;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    /**
     * @return list<EmbeddingRequest>
     */
    public function capturedRequests(): array
    {
        return $this->capturedRequests;
    }

    public function lastRequest(): ?EmbeddingRequest
    {
        return $this->capturedRequests[array_key_last($this->capturedRequests)] ?? null;
    }

    public function withException(\Throwable $exception): self
    {
        $this->exception = $exception;

        return $this;
    }

    public function withWrongDimension(int $dimension = 100): self
    {
        $this->returnWrongDimension = true;
        $this->wrongDimension = $dimension;

        return $this;
    }

    public function withDimension(int $dimension): self
    {
        $this->dimension = $dimension;

        return $this;
    }

    public function onCall(Closure $callback): self
    {
        $this->onCall = $callback;

        return $this;
    }

    public function reset(): void
    {
        $this->callCount = 0;
        $this->exception = null;
        $this->returnWrongDimension = false;
        $this->wrongDimension = 100;
        $this->capturedRequests = [];
        $this->onCall = null;
        $this->dimension = 1536;
    }
}
