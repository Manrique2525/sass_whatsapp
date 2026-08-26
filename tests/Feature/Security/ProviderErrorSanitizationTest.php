<?php

declare(strict_types=1);

use App\Domain\AI\Exceptions\AIAuthFailedException;
use App\Domain\AI\Exceptions\AIInvalidRequestException;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIRateLimitException;
use App\Domain\AI\ValueObjects\AIRequest;
use App\Domain\WhatsApp\Exceptions\WhatsAppAuthFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Infrastructure\AI\OpenAIProvider;
use App\Infrastructure\Billing\StripeProvider;
use App\Infrastructure\WhatsApp\MetaWhatsAppProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| FASE 26 U4 — Provider Error Sanitization (P2-1)
|--------------------------------------------------------------------------
|
| Verifica que los errores raw de proveedores (Meta, OpenAI, Stripe)
| NUNCA se exponen en los mensajes de excepción. Los errores raw se
| registran en log pero las excepciones contienen mensajes genéricos.
|
*/

function u4_wa_provider(): MetaWhatsAppProvider
{
    return new MetaWhatsAppProvider(
        'https://graph.facebook.com',
        'v26.0',
        'app-secret-test',
        'verify-token-test',
    );
}

function u4_ai_provider(): OpenAIProvider
{
    return new OpenAIProvider(
        apiKey: 'sk-test-key',
        model: 'gpt-4o-mini',
        baseUrl: 'https://api.openai.com/v1',
        timeout: 15,
        maxRetries: 0,
    );
}

function u4_stripe_provider(): StripeProvider
{
    return new StripeProvider(
        secretKey: 'sk_test_fake',
        webhookSecret: 'whsec_test',
    );
}

// ---------------------------------------------------------------------------
// ERR-01: Meta connection error no expone raw message
// ---------------------------------------------------------------------------

test('ERR-01: Meta connection error no expone raw message', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds'));

    Log::shouldReceive('warning')->once()->with('whatsapp.meta_connection_error', Mockery::type('array'));

    try {
        u4_wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
        $this->fail('Expected WhatsAppMessageFailedException');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->getMessage())->not->toContain('cURL error 28')
            ->and($e->getMessage())->not->toContain('Operation timed out')
            ->and($e->getMessage())->toContain('conexión');
    }
});

// ---------------------------------------------------------------------------
// ERR-02: Meta API error (400) no expone raw Meta message
// ---------------------------------------------------------------------------

test('ERR-02: Meta API error no expone raw Meta message', function (): void {
    Http::fake([
        'graph.facebook.com/v26.0/phone-1/messages' => Http::response([
            'error' => [
                'message' => '(#131026) Invalid parameter: To is not a valid WhatsApp ID',
                'type' => 'OAuthException',
                'code' => 131026,
            ],
        ], 400),
    ]);

    Log::shouldReceive('warning')->once()->with('whatsapp.meta_api_error', Mockery::on(fn (array $ctx) => str_contains((string) ($ctx['raw_message'] ?? ''), 'Invalid parameter: To is not a valid WhatsApp ID') && $ctx['status'] === 400));

    try {
        u4_wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
        $this->fail('Expected WhatsAppMessageFailedException');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->getMessage())->not->toContain('Invalid parameter')
            ->and($e->getMessage())->not->toContain('#131026')
            ->and($e->getMessage())->not->toContain('WhatsApp ID')
            ->and($e->getMessage())->toContain('enviar');
    }
});

// ---------------------------------------------------------------------------
// ERR-03: Meta API auth error no expone token detail
// ---------------------------------------------------------------------------

test('ERR-03: Meta API auth error no expone token detail', function (): void {
    Http::fake(fn () => Http::response([
        'error' => [
            'message' => 'Error validating access token: Session expired for user 123456',
            'type' => 'OAuthException',
            'code' => 190,
        ],
    ], 401));

    Log::shouldReceive('warning')->once()->with('whatsapp.meta_api_error', Mockery::type('array'));

    try {
        u4_wa_provider()->getPhoneNumberInfo('token-x', 'phone-1');
        $this->fail('Expected WhatsAppAuthFailedException');
    } catch (WhatsAppAuthFailedException $e) {
        expect($e->getMessage())->not->toContain('access token')
            ->and($e->getMessage())->not->toContain('Session expired')
            ->and($e->getMessage())->not->toContain('user 123456');
    }
});

// ---------------------------------------------------------------------------
// ERR-04: OpenAI 401 auth error no expone API key
// ---------------------------------------------------------------------------

test('ERR-04: OpenAI auth error no expone raw detail', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => [
                'message' => 'Incorrect API key provided: sk-fake123...invalid',
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], 401),
    ]);

    Log::shouldReceive('warning')->once()->with('ai.openai_api_error', Mockery::on(fn (array $ctx) => str_contains((string) ($ctx['raw_message'] ?? ''), 'Incorrect API key') && $ctx['status'] === 401));

    try {
        u4_ai_provider()->generateResponse(new AIRequest(prompt: 'Hi'));
        $this->fail('Expected AIAuthFailedException');
    } catch (AIAuthFailedException $e) {
        expect($e->getMessage())->not->toContain('sk-fake123')
            ->and($e->getMessage())->not->toContain('Incorrect API key');
    }
});

// ---------------------------------------------------------------------------
// ERR-05: OpenAI 429 rate limit no expone raw detail
// ---------------------------------------------------------------------------

test('ERR-05: OpenAI rate limit error no expone raw detail', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => [
                'message' => 'Rate limit reached for gpt-4o-mini on tokens per min',
                'type' => 'rate_limit_error',
                'code' => 'rate_limit_exceeded',
            ],
        ], 429),
    ]);

    Log::shouldReceive('warning')->once()->with('ai.openai_api_error', Mockery::on(fn (array $ctx) => str_contains((string) ($ctx['raw_message'] ?? ''), 'Rate limit reached') && $ctx['status'] === 429));

    try {
        u4_ai_provider()->generateResponse(new AIRequest(prompt: 'Hi'));
        $this->fail('Expected AIRateLimitException');
    } catch (AIRateLimitException $e) {
        expect($e->getMessage())->not->toContain('Rate limit reached')
            ->and($e->getMessage())->not->toContain('gpt-4o-mini');
    }
});

// ---------------------------------------------------------------------------
// ERR-06: OpenAI 500 server error no expone raw detail
// ---------------------------------------------------------------------------

test('ERR-06: OpenAI server error no expone raw detail', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => [
                'message' => 'The server had an error processing your request. Sorry about that!',
                'type' => 'server_error',
            ],
        ], 500),
    ]);

    Log::shouldReceive('warning')->once()->with('ai.openai_api_error', Mockery::on(fn (array $ctx) => str_contains((string) ($ctx['raw_message'] ?? ''), 'server had an error') && $ctx['status'] === 500));

    try {
        u4_ai_provider()->generateResponse(new AIRequest(prompt: 'Hi'));
        $this->fail('Expected AIProviderException');
    } catch (AIProviderException $e) {
        expect($e->getMessage())->not->toContain('The server had an error')
            ->and($e->retryable())->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// ERR-07: OpenAI 400 invalid request no expone raw detail
// ---------------------------------------------------------------------------

test('ERR-07: OpenAI invalid request error no expone raw detail', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => [
                'message' => "Invalid parameter: 'messages' must contain at least one message",
                'type' => 'invalid_request_error',
                'code' => 'invalid_request',
            ],
        ], 400),
    ]);

    Log::shouldReceive('warning')->once()->with('ai.openai_api_error', Mockery::on(fn (array $ctx) => str_contains((string) ($ctx['raw_message'] ?? ''), 'Invalid parameter') && $ctx['status'] === 400));

    try {
        u4_ai_provider()->generateResponse(new AIRequest(prompt: 'Hi'));
        $this->fail('Expected AIInvalidRequestException');
    } catch (AIInvalidRequestException $e) {
        expect($e->getMessage())->not->toContain("Invalid parameter: 'messages'")
            ->and($e->getMessage())->not->toContain('messages');
    }
});

// ---------------------------------------------------------------------------
// ERR-08: Meta raw error aparece en log pero no en exception
// ---------------------------------------------------------------------------

test('ERR-08: Meta raw error aparece en log', function (): void {
    Http::fake([
        'graph.facebook.com/v26.0/phone-1/messages' => Http::response([
            'error' => [
                'message' => 'SensitiveMetaDetail-12345',
                'type' => 'OAuthException',
                'code' => 99999,
            ],
        ], 403),
    ]);

    Log::shouldReceive('warning')->once()->with('whatsapp.meta_api_error', Mockery::on(fn (array $ctx) => (string) ($ctx['raw_message'] ?? '') === 'SensitiveMetaDetail-12345' && $ctx['provider_code'] === '99999'));

    try {
        u4_wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
    } catch (WhatsAppMessageFailedException) {
    }
});

// ---------------------------------------------------------------------------
// ERR-09: OpenAI raw error aparece en log
// ---------------------------------------------------------------------------

test('ERR-09: OpenAI raw error aparece en log', function (): void {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'error' => [
                'message' => 'SensitiveOpenAIDetail-67890',
                'type' => 'server_error',
            ],
        ], 503),
    ]);

    Log::shouldReceive('warning')->once()->with('ai.openai_api_error', Mockery::on(fn (array $ctx) => (string) ($ctx['raw_message'] ?? '') === 'SensitiveOpenAIDetail-67890' && $ctx['status'] === 503));

    try {
        u4_ai_provider()->generateResponse(new AIRequest(prompt: 'Hi'));
    } catch (AIProviderException) {
    }
});

// ---------------------------------------------------------------------------
// ERR-10: WhatsApp exception errorCode y status se preservan
// ---------------------------------------------------------------------------

test('ERR-10: WhatsApp exception errorCode y status se preservan', function (): void {
    Http::fake([
        'graph.facebook.com/v26.0/phone-1/messages' => Http::response([
            'error' => [
                'message' => 'some internal Meta error',
                'type' => 'OAuthException',
                'code' => 133000,
            ],
        ], 500),
    ]);

    Log::shouldReceive('warning')->once();

    try {
        u4_wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
        $this->fail('Expected exception');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->errorCode()->value)->toBe('WHATSAPP_MESSAGE_FAILED')
            ->and($e->status())->toBe(502)
            ->and($e->retryable())->toBeTrue();
    }
});
