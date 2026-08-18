<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\ValueObjects\AIRequest;
use App\Domain\AI\ValueObjects\AIResponse;
use Closure;

/**
 * Fake del AIProviderInterface para tests (FASE 16 U2).
 *
 * Permite configurar respuestas, excepciones y contar llamadas
 * sin acoplar los tests a OpenAI ni a Http::fake.
 */
final class FakeAIProvider implements AIProviderInterface
{
    private ?string $responseContent = null;

    private ?\Throwable $exception = null;

    private int $callCount = 0;

    /** @var list<AIRequest> */
    private array $capturedRequests = [];

    private ?Closure $onCall = null;

    public function generateResponse(AIRequest $request): AIResponse
    {
        $this->callCount++;
        $this->capturedRequests[] = $request;

        if ($this->onCall !== null) {
            ($this->onCall)($request);
        }

        if ($this->exception !== null) {
            throw $this->exception;
        }

        $content = $this->responseContent ?? 'Fake AI response';

        return new AIResponse(
            content: $content,
            provider: 'fake',
            model: 'fake-model',
            inputTokens: 10,
            outputTokens: 20,
            totalTokens: 30,
        );
    }

    public function withResponse(string $content): self
    {
        $this->responseContent = $content;

        return $this;
    }

    public function withException(\Throwable $exception): self
    {
        $this->exception = $exception;

        return $this;
    }

    public function onCall(Closure $callback): self
    {
        $this->onCall = $callback;

        return $this;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    /**
     * @return list<AIRequest>
     */
    public function capturedRequests(): array
    {
        return $this->capturedRequests;
    }

    public function lastRequest(): ?AIRequest
    {
        return $this->capturedRequests[array_key_last($this->capturedRequests)] ?? null;
    }

    public function reset(): void
    {
        $this->responseContent = null;
        $this->exception = null;
        $this->callCount = 0;
        $this->capturedRequests = [];
        $this->onCall = null;
    }
}
