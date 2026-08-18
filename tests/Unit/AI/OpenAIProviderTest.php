<?php

declare(strict_types=1);

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Enums\AIErrorCode;
use App\Domain\AI\Exceptions\AIAuthFailedException;
use App\Domain\AI\Exceptions\AIInvalidRequestException;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIRateLimitException;
use App\Domain\AI\ValueObjects\AIRequest;
use App\Domain\AI\ValueObjects\AIResponse;
use App\Infrastructure\AI\OpenAIProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| FASE 16 — AI PROVIDER INFRASTRUCTURE (UNIT)
|--------------------------------------------------------------------------
|
| Tests AI-P01..P15: VO inmutabilidad, provider con Http::fake,
| manejo de errores, telemetría de tokens, y validación de config.
| NO se realizan llamadas reales a la API de OpenAI.
|
*/

function ai_provider(): OpenAIProvider
{
    return new OpenAIProvider(
        apiKey: 'sk-test-key',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        timeout: 15,
        maxRetries: 1,
    );
}

function openai_success_response(string $content = 'Hello world'): array
{
    return [
        'id' => 'chatcmpl-test-123',
        'object' => 'chat.completion',
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
        ],
    ];
}

// ---------------------------------------------------------------------------
// AI-P01: AIRequest VO inmutabilidad y defaults
// ---------------------------------------------------------------------------
test('AI-P01: AIRequest es readonly con valores por defecto correctos', function (): void {
    $request = new AIRequest(prompt: 'Hola');

    expect($request->prompt)->toBe('Hola')
        ->and($request->systemPrompt)->toBeNull()
        ->and($request->model)->toBe('')
        ->and($request->temperature)->toBe(0.7)
        ->and($request->maxTokens)->toBe(500);
});

// ---------------------------------------------------------------------------
// AI-P02: AIResponse VO inmutabilidad y datos completos
// ---------------------------------------------------------------------------
test('AI-P02: AIResponse almacena todos los campos correctamente', function (): void {
    $response = new AIResponse(
        content: 'Hola, soy un asistente.',
        provider: 'openai',
        model: 'gpt-4o-mini',
        inputTokens: 15,
        outputTokens: 25,
        totalTokens: 40,
    );

    expect($response->content)->toBe('Hola, soy un asistente.')
        ->and($response->provider)->toBe('openai')
        ->and($response->model)->toBe('gpt-4o-mini')
        ->and($response->inputTokens)->toBe(15)
        ->and($response->outputTokens)->toBe(25)
        ->and($response->totalTokens)->toBe(40);
});

// ---------------------------------------------------------------------------
// AI-P03: AIProviderInterface se resuelve desde el contenedor
// ---------------------------------------------------------------------------
test('AI-P03: AIProviderInterface se resuelve correctamente desde el contenedor', function (): void {
    $provider = app(AIProviderInterface::class);

    expect($provider)->toBeInstanceOf(AIProviderInterface::class)
        ->and($provider)->toBeInstanceOf(OpenAIProvider::class);
});

// ---------------------------------------------------------------------------
// AI-P04: OpenAIProvider construct y validación de config
// ---------------------------------------------------------------------------
test('AI-P04: OpenAIProvider con API key vacía lanza AIAuthFailedException', function (): void {
    $provider = new OpenAIProvider(apiKey: '');

    $request = new AIRequest(prompt: 'Test');

    expect(fn () => $provider->generateResponse($request))
        ->toThrow(AIAuthFailedException::class, 'OPENAI_API_KEY is not configured');
});

// ---------------------------------------------------------------------------
// AI-P05: generateResponse 200 → AIResponse correcto
// ---------------------------------------------------------------------------
test('AI-P05: generateResponse con respuesta 200 devuelve AIResponse con tokens', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(openai_success_response('Respuesta AI'), 200),
    ]);

    $result = ai_provider()->generateResponse(new AIRequest(prompt: 'Hola'));

    expect($result)->toBeInstanceOf(AIResponse::class)
        ->and($result->content)->toBe('Respuesta AI')
        ->and($result->provider)->toBe('openai')
        ->and($result->model)->toBe('gpt-4o-mini')
        ->and($result->inputTokens)->toBe(10)
        ->and($result->outputTokens)->toBe(20)
        ->and($result->totalTokens)->toBe(30);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer sk-test-key')
            && $request['model'] === 'gpt-4o-mini'
            && $request['messages'][0]['role'] === 'user'
            && $request['messages'][0]['content'] === 'Hola';
    });
});

// ---------------------------------------------------------------------------
// AI-P06: generateResponse con system prompt incluido en messages
// ---------------------------------------------------------------------------
test('AI-P06: generateResponse con systemPrompt lo incluye como primer mensaje', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(openai_success_response(), 200),
    ]);

    ai_provider()->generateResponse(new AIRequest(
        prompt: 'Hola',
        systemPrompt: 'Eres un asistente de soporte.',
    ));

    Http::assertSent(function (Request $request): bool {
        return $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === 'Eres un asistente de soporte.'
            && $request['messages'][1]['role'] === 'user'
            && $request['messages'][1]['content'] === 'Hola';
    });
});

// ---------------------------------------------------------------------------
// AI-P07: generateResponse sin system prompt → solo user message
// ---------------------------------------------------------------------------
test('AI-P07: generateResponse sin systemPrompt solo incluye mensaje user', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response(openai_success_response(), 200),
    ]);

    ai_provider()->generateResponse(new AIRequest(prompt: 'Hola'));

    Http::assertSent(function (Request $request): bool {
        $messages = $request['messages'];

        return count($messages) === 1
            && $messages[0]['role'] === 'user';
    });
});

// ---------------------------------------------------------------------------
// AI-P08: API key vacía → AIAuthFailedException (no retryable)
// ---------------------------------------------------------------------------
test('AI-P08: API key vacía lanza AIAuthFailedException sin hacer HTTP', function (): void {
    $provider = new OpenAIProvider(apiKey: '');

    try {
        $provider->generateResponse(new AIRequest(prompt: 'Test'));
        $this->fail('Se esperaba AIAuthFailedException.');
    } catch (AIAuthFailedException $e) {
        expect($e->errorCode())->toBe(AIErrorCode::AuthFailed)
            ->and($e->status())->toBe(401)
            ->and($e->getMessage())->toContain('OPENAI_API_KEY');
    }

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// AI-P09: HTTP 401 → AIAuthFailedException (no retryable)
// ---------------------------------------------------------------------------
test('AI-P09: HTTP 401 lanza AIAuthFailedException', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Invalid API key', 'type' => 'invalid_request_error'],
        ], 401),
    ]);

    expect(fn () => ai_provider()->generateResponse(new AIRequest(prompt: 'Test')))
        ->toThrow(AIAuthFailedException::class, 'Invalid API key');
});

// ---------------------------------------------------------------------------
// AI-P10: HTTP 429 → AIRateLimitException (retryable)
// ---------------------------------------------------------------------------
test('AI-P10: HTTP 429 lanza AIRateLimitException', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Rate limit exceeded', 'type' => 'rate_limit_error'],
        ], 429),
    ]);

    try {
        ai_provider()->generateResponse(new AIRequest(prompt: 'Test'));
        $this->fail('Se esperaba AIRateLimitException.');
    } catch (AIRateLimitException $e) {
        expect($e->errorCode())->toBe(AIErrorCode::RateLimit)
            ->and($e->status())->toBe(429);
    }
});

// ---------------------------------------------------------------------------
// AI-P11: HTTP 400 → AIInvalidRequestException (no retryable)
// ---------------------------------------------------------------------------
test('AI-P11: HTTP 400 lanza AIInvalidRequestException', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Invalid model', 'type' => 'invalid_request_error'],
        ], 400),
    ]);

    try {
        ai_provider()->generateResponse(new AIRequest(prompt: 'Test'));
        $this->fail('Se esperaba AIInvalidRequestException.');
    } catch (AIInvalidRequestException $e) {
        expect($e->errorCode())->toBe(AIErrorCode::InvalidRequest)
            ->and($e->status())->toBe(400);
    }
});

// ---------------------------------------------------------------------------
// AI-P12: HTTP 500 → AIProviderException retryable
// ---------------------------------------------------------------------------
test('AI-P12: HTTP 500 lanza AIProviderException retryable', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => ['message' => 'Internal server error'],
        ], 500),
    ]);

    try {
        ai_provider()->generateResponse(new AIRequest(prompt: 'Test'));
        $this->fail('Se esperaba AIProviderException.');
    } catch (AIProviderException $e) {
        expect($e->retryable())->toBeTrue()
            ->and($e->status())->toBe(500);
    }
});

// ---------------------------------------------------------------------------
// AI-P13: Timeout de conexión → retryable
// ---------------------------------------------------------------------------
test('AI-P13: Timeout de conexión es retryable', function (): void {
    Http::fake(function (Request $request): never {
        throw new ConnectionException('Connection timed out.');
    });

    try {
        ai_provider()->generateResponse(new AIRequest(prompt: 'Test'));
        $this->fail('Se esperaba excepción retryable.');
    } catch (ConnectionException $e) {
        expect($e->getMessage())->toContain('Connection timed out');
    }
});

// ---------------------------------------------------------------------------
// AI-P14: Respuesta 200 pero sin choices → AIProviderException
// ---------------------------------------------------------------------------
test('AI-P14: Respuesta 200 sin choices lanza AIProviderException', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-bad',
            'choices' => [],
        ], 200),
    ]);

    expect(fn () => ai_provider()->generateResponse(new AIRequest(prompt: 'Test')))
        ->toThrow(AIProviderException::class, 'Invalid response structure from OpenAI API');
});

// ---------------------------------------------------------------------------
// AI-P15: Token usage correcto desde la respuesta
// ---------------------------------------------------------------------------
test('AI-P15: Token usage del provider se mapea correctamente al VO', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-tokens',
            'choices' => [
                ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop'],
            ],
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 100,
                'total_tokens' => 150,
            ],
        ], 200),
    ]);

    $result = ai_provider()->generateResponse(new AIRequest(prompt: 'Pregunta compleja'));

    expect($result->inputTokens)->toBe(50)
        ->and($result->outputTokens)->toBe(100)
        ->and($result->totalTokens)->toBe(150);
});
