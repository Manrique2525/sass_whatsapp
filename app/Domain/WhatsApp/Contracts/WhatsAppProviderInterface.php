<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Contracts;

use App\Domain\WhatsApp\ValueObjects\InteractiveMessage;
use App\Domain\WhatsApp\ValueObjects\MessageSendResult;
use App\Domain\WhatsApp\ValueObjects\PhoneNumberInfo;

/**
 * Contrato del proveedor de WhatsApp (FASE 6).
 *
 * La única implementación permitida es la Meta WhatsApp Cloud API oficial
 * (`MetaWhatsAppProvider`). La capa de aplicación inyecta ESTA interfaz y nunca
 * depende de Meta directamente.
 *
 * El `access_token` se pasa en cada llamada: es el token del WABA del tenant
 * (guardado cifrado en `whatsapp_accounts.access_token`), NUNCA un token global
 * de `.env`. Así el provider es stateless y multi-tenant por diseño.
 */
interface WhatsAppProviderInterface
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function sendText(string $accessToken, string $phoneId, string $to, string $text, array $context = []): MessageSendResult;

    /**
     * @param  list<array<string, mixed>|string>  $params
     */
    public function sendTemplate(string $accessToken, string $phoneId, string $to, string $templateName, string $language, array $params = []): MessageSendResult;

    public function sendImage(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $caption = ''): MessageSendResult;

    public function sendDocument(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $filename = ''): MessageSendResult;

    public function sendInteractiveMessage(string $accessToken, string $phoneId, string $to, InteractiveMessage $message): MessageSendResult;

    public function markAsRead(string $accessToken, string $phoneId, string $messageId): void;

    public function getPhoneNumberInfo(string $accessToken, string $phoneId): PhoneNumberInfo;

    public function subscribeToWebhooks(string $accessToken, string $wabaId): bool;

    public function unsubscribeFromWebhooks(string $accessToken, string $wabaId): bool;

    public function validateWebhookSignature(string $signature, string $rawBody): bool;

    /**
     * Verificación GET del webhook.
     *
     * @param  array<string, mixed>  $query
     * @return array{verified: bool, challenge: string|null}
     */
    public function verifyWebhook(array $query): array;
}
