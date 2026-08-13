<?php

declare(strict_types=1);

use App\Domain\WhatsApp\Exceptions\WhatsAppAuthFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppPhoneNotFoundException;
use App\Infrastructure\WhatsApp\MetaWhatsAppProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| FASE 6 — META WHATSAPP PROVIDER (UNIT)
|--------------------------------------------------------------------------
*/

function wa_provider(): MetaWhatsAppProvider
{
    return new MetaWhatsAppProvider(
        'https://graph.facebook.com',
        'v26.0',
        'app-secret-test',
        'verify-token-test',
    );
}

test('WHATSAPP-31: la firma del webhook se valida con HMAC-SHA256 y hash_equals', function (): void {
    $body = '{"hola":"mundo"}';
    $valid = 'sha256='.hash_hmac('sha256', $body, 'app-secret-test');

    expect(wa_provider()->validateWebhookSignature($valid, $body))->toBeTrue()
        ->and(wa_provider()->validateWebhookSignature('sha256=incorrecta', $body))->toBeFalse()
        ->and(wa_provider()->validateWebhookSignature('', $body))->toBeFalse();
});

test('WHATSAPP-32: la verificación GET acepta modo subscribe con el token correcto', function (): void {
    $provider = wa_provider();

    $result = $provider->verifyWebhook([
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'verify-token-test',
        'hub_challenge' => '1234567890',
    ]);

    expect($result)->toBe(['verified' => true, 'challenge' => '1234567890']);

    $wrong = $provider->verifyWebhook([
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'otro',
        'hub_challenge' => 'x',
    ]);

    expect($wrong)->toBe(['verified' => false, 'challenge' => null]);
});

test('WHATSAPP-33: sendText envía el payload oficial y devuelve el provider_message_id', function (): void {
    Http::fake([
        'graph.facebook.com/v26.0/phone-1/messages' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid-abc']],
        ], 200),
    ]);

    $result = wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');

    expect($result->providerMessageId)->toBe('wamid-abc');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://graph.facebook.com/v26.0/phone-1/messages'
            && $request['messaging_product'] === 'whatsapp'
            && $request['text']['body'] === 'Hola'
            && $request->hasHeader('Authorization', 'Bearer token-x');
    });
});

test('WHATSAPP-34: getPhoneNumberInfo con 401 lanza WhatsAppAuthFailedException', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
        ], 401),
    ]);

    expect(fn (): mixed => wa_provider()->getPhoneNumberInfo('token-malo', 'phone-1'))
        ->toThrow(WhatsAppAuthFailedException::class);
});

test('WHATSAPP-35: getPhoneNumberInfo con 404 lanza WhatsAppPhoneNotFoundException', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['code' => 100]], 404),
    ]);

    expect(fn (): mixed => wa_provider()->getPhoneNumberInfo('token-x', 'phone-inexistente'))
        ->toThrow(WhatsAppPhoneNotFoundException::class);
});

test('WHATSAPP-36: un error permanente (400) es no-retryable y conserva el código del provider', function (): void {
    Http::fake([
        'graph.facebook.com/*/messages' => Http::response([
            'error' => ['message' => '(#131030) Recipient not allowed.', 'code' => 131030],
        ], 400),
    ]);

    try {
        wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
        $this->fail('Se esperaba WhatsAppMessageFailedException.');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->providerErrorCode())->toBe('131030')
            ->and($e->retryable())->toBeFalse()
            ->and($e->status())->toBe(502);
    }
});

test('WHATSAPP-37: un error transitorio (500/429) es retryable', function (): void {
    Http::fake([
        'graph.facebook.com/*/messages' => Http::response(['error' => ['code' => 2]], 500),
    ]);

    try {
        wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
        $this->fail('Se esperaba WhatsAppMessageFailedException.');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->retryable())->toBeTrue();
    }
});

test('WHATSAPP-37b: un rate limit (429) es retryable', function (): void {
    Http::fake([
        'graph.facebook.com/*/messages' => Http::response(['error' => ['code' => 80007]], 429),
    ]);

    try {
        wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
        $this->fail('Se esperaba WhatsAppMessageFailedException.');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->retryable())->toBeTrue();
    }
});

test('WHATSAPP-38: un timeout de conexión se traduce a error transitorio', function (): void {
    Http::fake(function (Request $request): never {
        throw new ConnectionException('Connection timed out.');
    });

    try {
        wa_provider()->sendText('token-x', 'phone-1', '15550000001', 'Hola');
        $this->fail('Se esperaba WhatsAppMessageFailedException.');
    } catch (WhatsAppMessageFailedException $e) {
        expect($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toContain('conexión');
    }
});

test('WHATSAPP-39: subscribeToWebhooks delega en subscribed_apps y devuelve bool', function (): void {
    Http::fake([
        'graph.facebook.com/v26.0/waba-1/subscribed_apps' => Http::response(['success' => true], 200),
    ]);

    expect(wa_provider()->subscribeToWebhooks('token-x', 'waba-1'))->toBeTrue();
});

test('WHATSAPP-39b: subscribeToWebhooks con respuesta 403 devuelve false', function (): void {
    Http::fake([
        'graph.facebook.com/v26.0/waba-1/subscribed_apps' => Http::response(['error' => ['code' => 190]], 403),
    ]);

    expect(wa_provider()->subscribeToWebhooks('token-x', 'waba-1'))->toBeFalse();
});

test('WHATSAPP-40: unsubscribeFromWebhooks delega y devuelve bool', function (): void {
    Http::fake([
        'graph.facebook.com/v26.0/waba-1/subscribed_apps' => Http::response(['success' => true], 200),
    ]);

    expect(wa_provider()->unsubscribeFromWebhooks('token-x', 'waba-1'))->toBeTrue();
});
