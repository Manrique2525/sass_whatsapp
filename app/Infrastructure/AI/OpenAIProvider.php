<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Exceptions\AIAuthFailedException;
use App\Domain\AI\Exceptions\AIInvalidRequestException;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIRateLimitException;
use App\Domain\AI\ValueObjects\AIRequest;
use App\Domain\AI\ValueObjects\AIResponse;
use App\Infrastructure\Logging\SafeLogContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementación concreta del proveedor de IA usando OpenAI API (FASE 16).
 *
 * Stateless respecto al tenant: recibe datos ya preparados en AIRequest.
 * Utiliza el cliente HTTP de Laravel (Http facade) consistente con MetaWhatsAppProvider.
 * Endpoint: POST /v1/chat/completions
 */
final class OpenAIProvider implements AIProviderInterface
{
    private string $apiKey;

    private string $model;

    private string $baseUrl;

    private int $timeout;

    private int $maxRetries;

    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o-mini',
        string $baseUrl = 'https://api.openai.com/v1',
        int $timeout = 15,
        int $maxRetries = 1,
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    public function generateResponse(AIRequest $request): AIResponse
    {
        $this->validateConfig();

        $model = $request->model !== '' ? $request->model : $this->model;

        $payload = $this->buildPayload($request, $model);

        $response = $this->client()
            ->retry($this->maxRetries, 1000, function (Throwable $exception, PendingRequest $request, ?string $url): bool {
                return $exception instanceof ConnectionException;
            })
            ->post("{$this->baseUrl}/chat/completions", $payload);

        $this->handleError($response);

        return $this->parseResponse($response, $model);
    }

    private function validateConfig(): void
    {
        if ($this->apiKey === '') {
            throw new AIAuthFailedException('OPENAI_API_KEY is not configured');
        }
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout);
    }

    /**
     * @return array{model: string, messages: list<array{role: string, content: string}>, max_tokens: int, temperature: float}
     */
    private function buildPayload(AIRequest $request, string $model): array
    {
        $messages = [];

        if ($request->systemPrompt !== null) {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemPrompt,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $request->prompt,
        ];

        return [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
        ];
    }

    private function handleError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $rawBody = $response->json('error.message', 'Unknown error');

        Log::warning('ai.openai_api_error', [
            'status' => $status,
            'raw_message' => SafeLogContext::sanitizeProviderMessage($rawBody),
        ]);

        match (true) {
            $status === 401 => throw new AIAuthFailedException,
            $status === 429 => throw new AIRateLimitException,
            $status === 400 => throw new AIInvalidRequestException('Solicitud inválida al proveedor de IA.'),
            $status >= 500 => throw new AIProviderException('Error del servidor del proveedor de IA.', retryable: true, status: $status),
            default => throw new AIProviderException('Error desconocido del proveedor de IA.', status: $status),
        };
    }

    private function parseResponse(Response $response, string $model): AIResponse
    {
        $data = $response->json();

        if (! is_array($data) || ! isset($data['choices'][0]['message']['content'])) {
            throw new AIProviderException('Invalid response structure from OpenAI API');
        }

        $content = (string) $data['choices'][0]['message']['content'];

        $usage = $data['usage'] ?? [];
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? $inputTokens + $outputTokens);

        return new AIResponse(
            content: $content,
            provider: 'openai',
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
        );
    }
}
