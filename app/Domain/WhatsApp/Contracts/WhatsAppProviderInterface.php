<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Contracts;

use App\Domain\WhatsApp\ValueObjects\InteractiveMessage;
use App\Domain\WhatsApp\ValueObjects\MediaDownload;
use App\Domain\WhatsApp\ValueObjects\MediaMetadata;
use App\Domain\WhatsApp\ValueObjects\MessageSendResult;
use App\Domain\WhatsApp\ValueObjects\PhoneNumberInfo;
use App\Domain\WhatsApp\ValueObjects\TemplateInfo;

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

    /**
     * Lookup de metadata de un media por su id de Meta (FASE 31 U5).
     *
     * El media se referencia SIEMPRE por `provider_media_id` (nunca por URL
     * arbitrario); la URL de descarga temporal la devuelve Meta en la respuesta.
     */
    public function getMediaMetadata(string $accessToken, string $mediaId): MediaMetadata;

    /**
     * Descarga segura del contenido de un media de Meta (FASE 31 U5).
     *
     * Aplica protecciones de red (SSRF) sobre la URL temporal devuelta por el
     * provider, acota redirecciones y bytes, y NO reenvía el token de
     * autorización a hosts distintos. Devuelve el contenido como stream.
     */
    public function downloadMedia(string $accessToken, MediaMetadata $metadata, int $maxBytes): MediaDownload;

    /**
     * Lista el catálogo de templates de un WABA desde Meta (FASE 31 U5).
     *
     * @return list<TemplateInfo>
     */
    public function listTemplates(string $accessToken, string $wabaId): array;

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
