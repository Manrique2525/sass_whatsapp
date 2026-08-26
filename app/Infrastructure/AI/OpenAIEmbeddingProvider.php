<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\Exceptions\EmbeddingAuthFailedException;
use App\Domain\AI\Exceptions\EmbeddingDimensionMismatchException;
use App\Domain\AI\Exceptions\EmbeddingProviderException;
use App\Domain\AI\Exceptions\EmbeddingRateLimitException;
use App\Domain\AI\ValueObjects\EmbeddingRequest;
use App\Domain\AI\ValueObjects\EmbeddingResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Implementación concreta del proveedor de embeddings usando OpenAI API (FASE 17 U3.1).
 *
 * Stateless respecto al tenant: recibe datos ya preparados en EmbeddingRequest.
 * Utiliza el cliente HTTP de Laravel (Http facade) consistente con OpenAIProvider.
 * Endpoint: POST /v1/embeddings
 */
final class OpenAIEmbeddingProvider implements EmbeddingProviderInterface
{
    private string $apiKey;

    private string $model;

    private string $baseUrl;

    private int $dimensions;

    private int $maxBatchSize;

    private int $timeout;

    private int $maxRetries;

    public function __construct(
        string $apiKey,
        string $model = 'text-embedding-3-small',
        string $baseUrl = 'https://api.openai.com/v1',
        int $dimensions = 1536,
        int $maxBatchSize = 50,
        int $timeout = 30,
        int $maxRetries = 2,
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = $baseUrl;
        $this->dimensions = $dimensions;
        $this->maxBatchSize = $maxBatchSize;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->validateConfig();

        $model = $request->model !== '' ? $request->model : $this->model;

        $this->validateBatchSize($request);

        $payload = $this->buildPayload($request, $model);

        $response = $this->client()
            ->retry($this->maxRetries, 1000, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            })
            ->post("{$this->baseUrl}/embeddings", $payload);

        $this->handleError($response);

        return $this->parseResponse($response, $model, count($request->input));
    }

    private function validateConfig(): void
    {
        if ($this->apiKey === '') {
            throw new EmbeddingAuthFailedException('OPENAI_API_KEY is not configured');
        }
    }

    private function validateBatchSize(EmbeddingRequest $request): void
    {
        $count = count($request->input);

        if ($count > $this->maxBatchSize) {
            throw new InvalidArgumentException(
                "Embedding batch size {$count} exceeds maximum of {$this->maxBatchSize}.",
            );
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
     * @return array{model: string, input: list<string>}
     */
    private function buildPayload(EmbeddingRequest $request, string $model): array
    {
        return [
            'model' => $model,
            'input' => $request->input,
        ];
    }

    private function handleError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $rawBody = $response->json('error.message', 'Unknown error');

        Log::warning('ai.openai_embedding_api_error', [
            'status' => $status,
            'raw_message' => $rawBody,
        ]);

        match (true) {
            $status === 401 || $status === 403 => throw new EmbeddingAuthFailedException,
            $status === 429 => throw new EmbeddingRateLimitException,
            $status === 400 || $status === 422 => throw new EmbeddingProviderException('Solicitud de embedding inválida.', status: $status),
            $status >= 500 => throw new EmbeddingProviderException('Error del servidor del proveedor de IA.', retryable: true, status: $status),
            default => throw new EmbeddingProviderException('Error desconocido del proveedor de IA.', status: $status),
        };
    }

    /**
     * Parsea la respuesta de OpenAI y valida cardinalidad, índices y dimensionalidad.
     *
     * @param  int  $expectedCount  Número de embeddings esperados (igual que input count).
     */
    private function parseResponse(Response $response, string $model, int $expectedCount): EmbeddingResponse
    {
        $data = $response->json();

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            throw new EmbeddingProviderException('Invalid response structure from OpenAI API');
        }

        $data['data'] = $this->validateAndSortByIndex($data['data'], $expectedCount);

        $embeddings = [];

        /** @var array{index: int, embedding: list<float>} $item */
        foreach ($data['data'] as $item) {
            $vector = $item['embedding'];
            $this->validateVector($vector);
            $embeddings[] = $vector;
        }

        if (count($embeddings) !== $expectedCount) {
            throw new EmbeddingProviderException(
                "Expected {$expectedCount} embeddings, got ".count($embeddings).'.',
            );
        }

        $usage = $data['usage'] ?? [];
        $totalTokens = (int) ($usage['total_tokens'] ?? 0);

        return new EmbeddingResponse(
            embeddings: $embeddings,
            provider: 'openai',
            model: $model,
            totalInputTokens: $totalTokens,
        );
    }

    /**
     * Valida índices, detecta duplicados/gaps, y ordena por index.
     *
     * @param  list<array>  $data
     * @return list<array>
     */
    private function validateAndSortByIndex(array $data, int $expectedCount): array
    {
        if (count($data) !== $expectedCount) {
            throw new EmbeddingProviderException(
                "Expected {$expectedCount} embeddings, got ".count($data).'.',
            );
        }

        $seen = [];

        foreach ($data as $item) {
            $index = $item['index'] ?? null;

            if (! is_int($index)) {
                throw new EmbeddingProviderException('Embedding response missing valid index.');
            }

            if (isset($seen[$index])) {
                throw new EmbeddingProviderException("Duplicate index {$index} in embedding response.");
            }

            $seen[$index] = true;
        }

        for ($i = 0; $i < $expectedCount; $i++) {
            if (! isset($seen[$i])) {
                throw new EmbeddingProviderException("Missing index {$i} in embedding response.");
            }
        }

        usort($data, fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return $data;
    }

    /**
     * Valida que un vector sea numérico finito con la dimensionalidad correcta.
     *
     * @param  list<mixed>  $vector
     */
    private function validateVector(array $vector): void
    {
        if (count($vector) !== $this->dimensions) {
            throw new EmbeddingDimensionMismatchException($this->dimensions, count($vector));
        }

        foreach ($vector as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new EmbeddingProviderException('Embedding contains non-numeric or non-finite values.');
            }
        }
    }
}
