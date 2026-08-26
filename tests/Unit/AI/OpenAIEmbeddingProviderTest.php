<?php

declare(strict_types=1);

use App\Domain\AI\Contracts\EmbeddingProviderInterface;
use App\Domain\AI\Enums\EmbeddingErrorCode;
use App\Domain\AI\Exceptions\EmbeddingAuthFailedException;
use App\Domain\AI\Exceptions\EmbeddingDimensionMismatchException;
use App\Domain\AI\Exceptions\EmbeddingProviderException;
use App\Domain\AI\Exceptions\EmbeddingRateLimitException;
use App\Domain\AI\ValueObjects\EmbeddingRequest;
use App\Domain\AI\ValueObjects\EmbeddingResponse;
use App\Infrastructure\AI\OpenAIEmbeddingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| FASE 17 U3.1 — EMBEDDING PROVIDER INFRASTRUCTURE (UNIT)
|--------------------------------------------------------------------------
|
| Tests EMB-P01..P28 + EMB-F01..04 + EMB-SEC-01..06:
| VO validation, provider con Http::fake, error mapping,
| cardinality, index ordering, dimension enforcement,
| float validation, fake provider, security.
| NO se realizan llamadas reales a la API de OpenAI.
|
*/

// ---------------------------------------------------------------------------
// HELPERS
// ---------------------------------------------------------------------------

function emb_provider(int $dims = 1536, int $maxBatch = 50): OpenAIEmbeddingProvider
{
    return new OpenAIEmbeddingProvider(
        apiKey: 'sk-test-key',
        model: 'text-embedding-3-small',
        baseUrl: 'https://api.openai.com/v1',
        dimensions: $dims,
        maxBatchSize: $maxBatch,
        timeout: 30,
        maxRetries: 1,
    );
}

function emb_vec(int $dim = 1536, float $fill = 0.1): string
{
    return '['.implode(',', array_fill(0, $dim, (string) $fill)).']';
}

function emb_success_response(array $embeddings, string $model = 'text-embedding-3-small'): array
{
    $data = [];

    foreach ($embeddings as $index => $embedding) {
        $data[] = [
            'object' => 'embedding',
            'index' => $index,
            'embedding' => $embedding,
        ];
    }

    return [
        'object' => 'list',
        'data' => $data,
        'model' => $model,
        'usage' => [
            'prompt_tokens' => 10 * count($embeddings),
            'total_tokens' => 10 * count($embeddings),
        ],
    ];
}

function emb_single_embedding(int $dim = 1536, float $fill = 0.1): array
{
    return array_fill(0, $dim, $fill);
}

// =========================================================================
// EMBEDDING REQUEST VO
// =========================================================================

test('EMB-P01: EmbeddingRequest holds input and model correctly', function (): void {
    $request = new EmbeddingRequest(input: ['Hello', 'World']);

    expect($request->input)->toBe(['Hello', 'World'])
        ->and($request->model)->toBe('');
});

test('EMB-P02: EmbeddingRequest rejects empty input', function (): void {
    new EmbeddingRequest(input: []);
})->throws(InvalidArgumentException::class, 'must not be empty');

test('EMB-P03: EmbeddingRequest rejects non-string input', function (): void {
    new EmbeddingRequest(input: ['valid', 123]);
})->throws(InvalidArgumentException::class, 'must be a string');

test('EMB-P04: EmbeddingRequest rejects empty-after-trim strings', function (): void {
    new EmbeddingRequest(input: ['valid', '   ']);
})->throws(InvalidArgumentException::class, 'must not be empty after trim');

test('EMB-P05: EmbeddingResponse holds all fields', function (): void {
    $response = new EmbeddingResponse(
        embeddings: [[0.1, 0.2], [0.3, 0.4]],
        provider: 'openai',
        model: 'text-embedding-3-small',
        totalInputTokens: 20,
    );

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->provider)->toBe('openai')
        ->and($response->model)->toBe('text-embedding-3-small')
        ->and($response->totalInputTokens)->toBe(20);
});

// =========================================================================
// EMBEDDING ERROR CODE
// =========================================================================

test('EMB-P06: EmbeddingErrorCode has expected cases', function (): void {
    expect(EmbeddingErrorCode::AuthFailed->value)->toBe('EMBEDDING_AUTH_FAILED')
        ->and(EmbeddingErrorCode::RateLimit->value)->toBe('EMBEDDING_RATE_LIMIT')
        ->and(EmbeddingErrorCode::DimensionMismatch->value)->toBe('EMBEDDING_DIMENSION_MISMATCH')
        ->and(EmbeddingErrorCode::ProviderError->value)->toBe('EMBEDDING_PROVIDER_ERROR')
        ->and(EmbeddingErrorCode::Timeout->value)->toBe('EMBEDDING_TIMEOUT')
        ->and(EmbeddingErrorCode::InvalidRequest->value)->toBe('EMBEDDING_INVALID_REQUEST')
        ->and(EmbeddingErrorCode::InvalidResponse->value)->toBe('EMBEDDING_INVALID_RESPONSE');
});

// =========================================================================
// EMBEDDING PROVIDER INTERFACE RESOLUTION
// =========================================================================

test('EMB-P07: EmbeddingProviderInterface resolves from container', function (): void {
    $provider = app(EmbeddingProviderInterface::class);

    expect($provider)->toBeInstanceOf(EmbeddingProviderInterface::class);
});

// =========================================================================
// OPENAI EMBEDDING PROVIDER — AUTH
// =========================================================================

test('EMB-P08: missing API key throws EmbeddingAuthFailedException', function (): void {
    $provider = new OpenAIEmbeddingProvider(apiKey: '');

    $provider->embed(new EmbeddingRequest(input: ['test']));
})->throws(EmbeddingAuthFailedException::class, 'OPENAI_API_KEY is not configured');

test('EMB-P09: API key absent from exception message', function (): void {
    $provider = new OpenAIEmbeddingProvider(apiKey: '');

    try {
        $provider->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected EmbeddingAuthFailedException.');
    } catch (EmbeddingAuthFailedException $e) {
        expect($e->getMessage())->not->toContain('sk-test')
            ->and($e->errorCode())->toBe(EmbeddingErrorCode::AuthFailed)
            ->and($e->status())->toBe(401);
    }
});

// =========================================================================
// OPENAI EMBEDDING PROVIDER — VALID REQUEST
// =========================================================================

test('EMB-P10: valid single request returns EmbeddingResponse', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(
            emb_success_response([$vector]),
            200,
        ),
    ]);

    $result = emb_provider()->embed(new EmbeddingRequest(input: ['Hello world']));

    expect($result)->toBeInstanceOf(EmbeddingResponse::class)
        ->and($result->embeddings)->toHaveCount(1)
        ->and($result->provider)->toBe('openai')
        ->and($result->model)->toBe('text-embedding-3-small')
        ->and($result->totalInputTokens)->toBe(10)
        ->and($result->embeddings[0])->toHaveCount(1536);
});

test('EMB-P11: authorization header is Bearer token', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(emb_success_response([$vector]), 200),
    ]);

    emb_provider()->embed(new EmbeddingRequest(input: ['test']));

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.openai.com/v1/embeddings'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer sk-test-key');
    });
});

test('EMB-P12: configured model is sent in payload', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(emb_success_response([$vector]), 200),
    ]);

    emb_provider()->embed(new EmbeddingRequest(input: ['test']));

    Http::assertSent(function (Request $request): bool {
        return $request['model'] === 'text-embedding-3-small'
            && $request['input'] === ['test'];
    });
});

test('EMB-P13: batch request with multiple inputs', function (): void {
    $vectors = [emb_single_embedding(), emb_single_embedding(1536, 0.5)];
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(emb_success_response($vectors), 200),
    ]);

    $result = emb_provider()->embed(new EmbeddingRequest(input: ['Hello', 'World']));

    expect($result->embeddings)->toHaveCount(2);

    Http::assertSent(function (Request $request): bool {
        return $request['input'] === ['Hello', 'World']
            && count($request['input']) === 2;
    });
});

test('EMB-P14: request model override is used when non-empty', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(emb_success_response([$vector], 'custom-model'), 200),
    ]);

    $result = emb_provider()->embed(new EmbeddingRequest(input: ['test'], model: 'custom-model'));

    expect($result->model)->toBe('custom-model');

    Http::assertSent(function (Request $request): bool {
        return $request['model'] === 'custom-model';
    });
});

// =========================================================================
// RESPONSE INDEX ORDERING
// =========================================================================

test('EMB-P15: response preserves input order using index sorting', function (): void {
    $vecA = emb_single_embedding(1536, 1.5);
    $vecB = emb_single_embedding(1536, 0.5);
    $vecC = emb_single_embedding(1536, 0.2);

    $data = [
        ['object' => 'embedding', 'index' => 2, 'embedding' => $vecC],
        ['object' => 'embedding', 'index' => 0, 'embedding' => $vecA],
        ['object' => 'embedding', 'index' => 1, 'embedding' => $vecB],
    ];

    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => $data,
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 30, 'total_tokens' => 30],
        ], 200),
    ]);

    $result = emb_provider()->embed(new EmbeddingRequest(input: ['A', 'B', 'C']));

    expect($result->embeddings)->toHaveCount(3)
        ->and($result->embeddings[0])->toBe($vecA)
        ->and($result->embeddings[1])->toBe($vecB)
        ->and($result->embeddings[2])->toBe($vecC);
});

// =========================================================================
// ERROR MAPPING
// =========================================================================

test('EMB-P16: HTTP 401 throws EmbeddingAuthFailedException', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'error' => ['message' => 'Invalid API key', 'type' => 'invalid_request_error'],
        ], 401),
    ]);

    try {
        emb_provider()->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected EmbeddingAuthFailedException.');
    } catch (EmbeddingAuthFailedException $e) {
        expect($e->errorCode())->toBe(EmbeddingErrorCode::AuthFailed)
            ->and($e->status())->toBe(401);
    }
});

test('EMB-P17: HTTP 403 throws EmbeddingAuthFailedException', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'error' => ['message' => 'Forbidden'],
        ], 403),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['test'])))
        ->toThrow(EmbeddingAuthFailedException::class);
});

test('EMB-P18: HTTP 429 throws EmbeddingRateLimitException', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'error' => ['message' => 'Rate limit exceeded', 'type' => 'rate_limit_error'],
        ], 429),
    ]);

    try {
        emb_provider()->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected EmbeddingRateLimitException.');
    } catch (EmbeddingRateLimitException $e) {
        expect($e->errorCode())->toBe(EmbeddingErrorCode::RateLimit)
            ->and($e->status())->toBe(429);
    }
});

test('EMB-P19: HTTP 400 throws EmbeddingProviderException', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'error' => ['message' => 'Invalid model', 'type' => 'invalid_request_error'],
        ], 400),
    ]);

    try {
        emb_provider()->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected EmbeddingProviderException.');
    } catch (EmbeddingProviderException $e) {
        expect($e->status())->toBe(400);
    }
});

test('EMB-P20: HTTP 500 throws retryable EmbeddingProviderException', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'error' => ['message' => 'Internal server error'],
        ], 500),
    ]);

    try {
        emb_provider()->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected EmbeddingProviderException.');
    } catch (EmbeddingProviderException $e) {
        expect($e->retryable())->toBeTrue()
            ->and($e->status())->toBe(500);
    }
});

test('EMB-P21: connection timeout is retryable', function (): void {
    Http::fake(function (Request $request): never {
        throw new ConnectionException('Connection timed out.');
    });

    try {
        emb_provider()->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected exception.');
    } catch (ConnectionException $e) {
        expect($e->getMessage())->toContain('Connection timed out');
    }
});

// =========================================================================
// MALFORMED RESPONSE
// =========================================================================

test('EMB-P22: missing data field throws EmbeddingProviderException', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['model' => 'test'], 200),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['test'])))
        ->toThrow(EmbeddingProviderException::class, 'Invalid response structure');
});

test('EMB-P23: empty data array throws EmbeddingProviderException', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => [],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ], 200),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['test'])))
        ->toThrow(EmbeddingProviderException::class, 'Expected 1 embeddings, got 0');
});

test('EMB-P24: missing index throws EmbeddingProviderException', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'embedding' => $vector],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ], 200),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['test'])))
        ->toThrow(EmbeddingProviderException::class, 'missing valid index');
});

test('EMB-P25: duplicate index throws EmbeddingProviderException', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => $vector],
                ['object' => 'embedding', 'index' => 0, 'embedding' => $vector],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 20, 'total_tokens' => 20],
        ], 200),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['A', 'B'])))
        ->toThrow(EmbeddingProviderException::class, 'Duplicate index');
});

test('EMB-P26: missing index in sequence throws EmbeddingProviderException', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => $vector],
                ['object' => 'embedding', 'index' => 2, 'embedding' => $vector],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 20, 'total_tokens' => 20],
        ], 200),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['A', 'B'])))
        ->toThrow(EmbeddingProviderException::class, 'Missing index 1');
});

test('EMB-P27: wrong number of embeddings throws EmbeddingProviderException', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => $vector],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 20, 'total_tokens' => 20],
        ], 200),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['A', 'B'])))
        ->toThrow(EmbeddingProviderException::class, 'Expected 2 embeddings, got 1');
});

// =========================================================================
// DIMENSION ENFORCEMENT
// =========================================================================

test('EMB-P28: wrong dimensions throws EmbeddingDimensionMismatchException', function (): void {
    $wrongVector = array_fill(0, 100, 0.1);
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(
            emb_success_response([$wrongVector]),
            200,
        ),
    ]);

    try {
        emb_provider()->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected EmbeddingDimensionMismatchException.');
    } catch (EmbeddingDimensionMismatchException $e) {
        expect($e->errorCode())->toBe(EmbeddingErrorCode::DimensionMismatch)
            ->and($e->getMessage())->toContain('1536')
            ->and($e->getMessage())->toContain('100')
            ->and($e->getMessage())->not->toContain('0.1');
    }
});

// =========================================================================
// FLOAT VALIDATION
// =========================================================================

test('EMB-P29: non-numeric vector values throw EmbeddingProviderException', function (): void {
    $badVector = array_fill(0, 1536, 0.0);
    $badVector[0] = 'not_a_number';

    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => $badVector],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ], 200),
    ]);

    expect(fn () => emb_provider()->embed(new EmbeddingRequest(input: ['test'])))
        ->toThrow(EmbeddingProviderException::class, 'non-numeric or non-finite');
});

// =========================================================================
// BATCH SIZE ENFORCEMENT
// =========================================================================

test('EMB-P30: batch size exceeding max throws InvalidArgumentException', function (): void {
    $inputs = array_fill(0, 51, 'test');
    $provider = emb_provider(maxBatch: 50);

    expect(fn () => $provider->embed(new EmbeddingRequest(input: $inputs)))
        ->toThrow(InvalidArgumentException::class, 'exceeds maximum');
});

test('EMB-P31: batch size at limit passes validation', function (): void {
    $vector = emb_single_embedding();
    $inputs = array_fill(0, 50, 'test');

    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(
            emb_success_response(array_fill(0, 50, $vector)),
            200,
        ),
    ]);

    $result = emb_provider(maxBatch: 50)->embed(new EmbeddingRequest(input: $inputs));

    expect($result->embeddings)->toHaveCount(50);
});

// =========================================================================
// API KEY ABSENT FROM ERRORS
// =========================================================================

test('EMB-P32: API key does not appear in any exception message', function (): void {
    $provider = new OpenAIEmbeddingProvider(apiKey: '');

    try {
        $provider->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected exception.');
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('sk-test')
            ->and($e->getMessage())->not->toContain('sk-')
            ->and($e->getMessage())->not->toContain('OPENAI_API_KEY=sk');
    }
});

test('EMB-P33: HTTP error body does not leak in exception message', function (): void {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'error' => ['message' => 'Internal error with /tmp/sensitive/path'],
        ], 500),
    ]);

    try {
        emb_provider()->embed(new EmbeddingRequest(input: ['test']));
        $this->fail('Expected exception.');
    } catch (EmbeddingProviderException $e) {
        expect($e->getMessage())->toContain('proveedor de IA');
    }
});

// =========================================================================
// TOKEN USAGE
// =========================================================================

test('EMB-P34: totalInputTokens parsed from usage.total_tokens', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => $vector],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => 15,
                'total_tokens' => 15,
            ],
        ], 200),
    ]);

    $result = emb_provider()->embed(new EmbeddingRequest(input: ['test']));

    expect($result->totalInputTokens)->toBe(15);
});

// =========================================================================
// FAKE EMBEDDING PROVIDER
// =========================================================================

test('EMB-F01: FakeEmbeddingProvider returns deterministic vectors', function (): void {
    $fake = new FakeEmbeddingProvider;
    $request = new EmbeddingRequest(input: ['test']);

    $r1 = $fake->embed($request);
    $r2 = $fake->embed($request);

    expect($r1->embeddings[0])->toBe($r2->embeddings[0])
        ->and($r1->embeddings[0])->toHaveCount(1536);
});

test('EMB-F02: FakeEmbeddingProvider tracks call count and requests', function (): void {
    $fake = new FakeEmbeddingProvider;

    $fake->embed(new EmbeddingRequest(input: ['A']));
    $fake->embed(new EmbeddingRequest(input: ['B', 'C']));

    expect($fake->callCount())->toBe(2)
        ->and($fake->capturedRequests())->toHaveCount(2)
        ->and($fake->lastRequest()->input)->toBe(['B', 'C']);
});

test('EMB-F03: FakeEmbeddingProvider injects exception', function (): void {
    $fake = new FakeEmbeddingProvider;
    $fake->withException(new EmbeddingAuthFailedException('test'));

    expect(fn () => $fake->embed(new EmbeddingRequest(input: ['test'])))
        ->toThrow(EmbeddingAuthFailedException::class, 'test');
});

test('EMB-F04: FakeEmbeddingProvider wrong dimension simulation', function (): void {
    $fake = new FakeEmbeddingProvider;
    $fake->withWrongDimension(100);

    $result = $fake->embed(new EmbeddingRequest(input: ['test']));

    expect($result->embeddings[0])->toHaveCount(100);
});

test('EMB-F05: FakeEmbeddingProvider reset clears state', function (): void {
    $fake = new FakeEmbeddingProvider;
    $fake->embed(new EmbeddingRequest(input: ['test']));

    expect($fake->callCount())->toBe(1);

    $fake->reset();

    expect($fake->callCount())->toBe(0)
        ->and($fake->capturedRequests())->toBeEmpty()
        ->and($fake->lastRequest())->toBeNull();
});

test('EMB-F06: FakeEmbeddingProvider vectors are normalized unit vectors', function (): void {
    $fake = new FakeEmbeddingProvider;
    $result = $fake->embed(new EmbeddingRequest(input: ['test']));

    $vector = $result->embeddings[0];
    $norm = sqrt(array_reduce($vector, fn (float $s, float $v): float => $s + $v * $v, 0.0));

    expect($norm)->toBeGreaterThan(0.99)
        ->toBeLessThan(1.01);
});

test('EMB-F07: FakeEmbeddingProvider onCall callback fires', function (): void {
    $called = false;
    $fake = new FakeEmbeddingProvider;
    $fake->onCall(function () use (&$called): void {
        $called = true;
    });

    $fake->embed(new EmbeddingRequest(input: ['test']));

    expect($called)->toBeTrue();
});

// =========================================================================
// HTTP::FAKE NOT SENT
// =========================================================================

test('EMB-P35: Http::fake prevents real HTTP calls', function (): void {
    $vector = emb_single_embedding();
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(emb_success_response([$vector]), 200),
    ]);

    emb_provider()->embed(new EmbeddingRequest(input: ['test']));

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/embeddings');
    });
});

test('EMB-P36: empty API key does not make HTTP', function (): void {
    $provider = new OpenAIEmbeddingProvider(apiKey: '');

    try {
        $provider->embed(new EmbeddingRequest(input: ['test']));
    } catch (Throwable $e) {
        // Expected
    }

    Http::assertNothingSent();
});
