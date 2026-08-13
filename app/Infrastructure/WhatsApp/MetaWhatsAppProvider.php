<?php

declare(strict_types=1);

namespace App\Infrastructure\WhatsApp;

use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Exceptions\WhatsAppAuthFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppMessageFailedException;
use App\Domain\WhatsApp\Exceptions\WhatsAppPhoneNotFoundException;
use App\Domain\WhatsApp\ValueObjects\InteractiveMessage;
use App\Domain\WhatsApp\ValueObjects\MessageSendResult;
use App\Domain\WhatsApp\ValueObjects\PhoneNumberInfo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Proveedor oficial de la Meta WhatsApp Cloud API (FASE 6, ADR-029).
 *
 * - El token de cada llamada es el del WABA del tenant (nunca un token global).
 * - El resultado se normaliza en `MessageSendResult`; los errores de Meta se
 *   mapean a excepciones de dominio con el código de error del provider y la
 *   marca `retryable` (transitorios: timeout/5xx/429; permanentes: 4xx).
 * - La firma del webhook (X-Hub-Signature-256) se valida SIEMPRE sobre el body
 *   crudo con hash_equals; jamás sobre un JSON re-serializado.
 */
final class MetaWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(
        private readonly string $graphUrl,
        private readonly string $graphVersion,
        private readonly string $appSecret,
        private readonly string $verifyToken,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function sendText(string $accessToken, string $phoneId, string $to, string $text, array $context = []): MessageSendResult
    {
        $response = $this->http(fn (): Response => $this->client($accessToken)->post("/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $text,
            ],
        ]));

        return $this->mapSendResponse($response, 'No se pudo enviar el mensaje de texto.');
    }

    /**
     * @param  list<array<string, mixed>|string>  $params
     */
    public function sendTemplate(string $accessToken, string $phoneId, string $to, string $templateName, string $language, array $params = []): MessageSendResult
    {
        $components = [];

        if ($params !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (mixed $param): array => is_array($param) ? $param : ['type' => 'text', 'text' => (string) $param],
                    $params,
                ),
            ];
        }

        $response = $this->http(fn (): Response => $this->client($accessToken)->post("/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]));

        return $this->mapSendResponse($response, 'No se pudo enviar la plantilla.');
    }

    public function sendImage(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $caption = ''): MessageSendResult
    {
        $image = ['link' => $mediaUrl];

        if ($caption !== '') {
            $image['caption'] = $caption;
        }

        $response = $this->http(fn (): Response => $this->client($accessToken)->post("/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => $image,
        ]));

        return $this->mapSendResponse($response, 'No se pudo enviar la imagen.');
    }

    public function sendDocument(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $filename = ''): MessageSendResult
    {
        $document = ['link' => $mediaUrl];

        if ($filename !== '') {
            $document['filename'] = $filename;
        }

        $response = $this->http(fn (): Response => $this->client($accessToken)->post("/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ]));

        return $this->mapSendResponse($response, 'No se pudo enviar el documento.');
    }

    public function sendInteractiveMessage(string $accessToken, string $phoneId, string $to, InteractiveMessage $message): MessageSendResult
    {
        $response = $this->http(fn (): Response => $this->client($accessToken)->post("/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $message->toArray(),
        ]));

        return $this->mapSendResponse($response, 'No se pudo enviar el mensaje interactivo.');
    }

    public function markAsRead(string $accessToken, string $phoneId, string $messageId): void
    {
        $this->http(fn (): Response => $this->client($accessToken)->post("/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]))->throw();
    }

    public function getPhoneNumberInfo(string $accessToken, string $phoneId): PhoneNumberInfo
    {
        $response = $this->http(fn (): Response => $this->client($accessToken)->get("/{$phoneId}", [
            'fields' => 'verified_name,display_phone_number,quality_rating,status',
        ]));

        if ($response->status() === 401 || $response->status() === 403) {
            throw new WhatsAppAuthFailedException($this->metaErrorMessage($response, 'Token de WhatsApp inválido o sin permisos.'));
        }

        if ($response->status() === 404) {
            throw new WhatsAppPhoneNotFoundException;
        }

        if (! $response->successful()) {
            throw $this->messageException($response, 'No se pudo consultar el número en Meta.');
        }

        return PhoneNumberInfo::fromMeta((array) $response->json());
    }

    public function subscribeToWebhooks(string $accessToken, string $wabaId): bool
    {
        $response = $this->http(fn (): Response => $this->client($accessToken)->post("/{$wabaId}/subscribed_apps"));

        return $response->successful();
    }

    public function unsubscribeFromWebhooks(string $accessToken, string $wabaId): bool
    {
        $response = $this->http(fn (): Response => $this->client($accessToken)->delete("/{$wabaId}/subscribed_apps"));

        return $response->successful();
    }

    public function validateWebhookSignature(string $signature, string $rawBody): bool
    {
        if ($this->appSecret === '' || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $this->appSecret);

        return hash_equals($expected, $signature);
    }

    public function verifyWebhook(array $query): array
    {
        // PHP convierte puntos a guiones bajos en los parámetros de query
        // (`hub.mode` llega como `hub_mode` en $_GET); se leen ambas variantes.
        $mode = (string) ($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = $query['hub_challenge'] ?? $query['hub.challenge'] ?? null;

        if ($mode !== 'subscribe' || ! hash_equals($this->verifyToken, $token)) {
            return ['verified' => false, 'challenge' => null];
        }

        return ['verified' => true, 'challenge' => is_scalar($challenge) ? (string) $challenge : null];
    }

    private function client(string $accessToken): PendingRequest
    {
        return Http::baseUrl(rtrim($this->graphUrl, '/').'/'.$this->graphVersion)
            ->withToken($accessToken)
            ->acceptJson()
            ->timeout(10);
    }

    /**
     * Ejecuta la llamada HTTP traduciendo errores de conexión/timeout a
     * `WhatsAppMessageFailedException` transitoria (reintentable).
     *
     * @param  callable(): Response  $callable
     */
    private function http(callable $callable): Response
    {
        try {
            return $callable();
        } catch (ConnectionException $e) {
            throw new WhatsAppMessageFailedException(
                'Error de conexión con Meta: '.$e->getMessage(),
                null,
                true,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function body(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    private function metaErrorMessage(Response $response, string $fallback): string
    {
        $body = $this->body($response);

        return is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : $fallback;
    }

    private function mapSendResponse(Response $response, string $fallback): MessageSendResult
    {
        if ($response->successful()) {
            $body = $this->body($response) ?? [];

            $providerMessageId = (string) ($body['messages'][0]['id'] ?? '');

            return MessageSendResult::success($providerMessageId, null, $body);
        }

        throw $this->messageException($response, $fallback);
    }

    private function messageException(Response $response, string $fallback): WhatsAppMessageFailedException
    {
        $body = $this->body($response);

        $providerCode = is_array($body) && isset($body['error']['code'])
            ? (string) $body['error']['code']
            : '';

        $message = is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : $fallback;

        $retryable = $response->serverError() || $response->status() === 429;

        return new WhatsAppMessageFailedException(
            $message,
            $providerCode !== '' ? $providerCode : null,
            $retryable,
        );
    }
}
