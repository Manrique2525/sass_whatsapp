<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\ValueObjects\InteractiveMessage;
use App\Domain\WhatsApp\ValueObjects\MediaDownload;
use App\Domain\WhatsApp\ValueObjects\MediaMetadata;
use App\Domain\WhatsApp\ValueObjects\MessageSendResult;
use App\Domain\WhatsApp\ValueObjects\PhoneNumberInfo;
use App\Domain\WhatsApp\ValueObjects\TemplateInfo;

/**
 * Fake del `WhatsAppProviderInterface` SOLO para el entorno E2E (FASE 30 U2).
 *
 * Sustituye a `MetaWhatsAppProvider` en `E2EOnlyServiceProvider` (únicamente
 * cuando `APP_ENV === 'e2e'`) para que Playwright jamás alcance la Graph API de
 * Meta. Implementa el contrato real y devuelve resultados sintéticos.
 *
 * - NO hace HTTP. Es fail-closed por construcción: ninguna ruta del runtime E2E
 *   puede llegar a la implementación real de Meta.
 * - Registra SOLO metadata mínima para asserts: un contador de invocaciones y
 *   el marcador del tenant derivado del `access_token` (token sintético E2E,
 *   nunca PII). No almacena el destinatario (`to`) ni contenidos.
 * - Devuelve `providerMessageId` sintético determinista por tipo de mensaje.
 */
final class FakeWhatsAppProvider implements WhatsAppProviderInterface
{
    private int $textSendCount = 0;

    private int $invocationCount = 0;

    /** Marcador del tenant del último envío (derivado del access_token E2E). */
    private ?string $lastTenantMarker = null;

    public function sendText(string $accessToken, string $phoneId, string $to, string $text, array $context = []): MessageSendResult
    {
        $this->recordInvocation($accessToken);
        $this->textSendCount++;

        return $this->success($phoneId);
    }

    public function sendTemplate(string $accessToken, string $phoneId, string $to, string $templateName, string $language, array $params = []): MessageSendResult
    {
        $this->recordInvocation($accessToken);

        return $this->success($phoneId);
    }

    public function sendImage(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $caption = ''): MessageSendResult
    {
        $this->recordInvocation($accessToken);

        return $this->success($phoneId);
    }

    public function sendDocument(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $filename = ''): MessageSendResult
    {
        $this->recordInvocation($accessToken);

        return $this->success($phoneId);
    }

    public function sendInteractiveMessage(string $accessToken, string $phoneId, string $to, InteractiveMessage $message): MessageSendResult
    {
        $this->recordInvocation($accessToken);

        return $this->success($phoneId);
    }

    public function markAsRead(string $accessToken, string $phoneId, string $messageId): void
    {
        $this->recordInvocation($accessToken);
    }

    public function getPhoneNumberInfo(string $accessToken, string $phoneId): PhoneNumberInfo
    {
        $this->recordInvocation($accessToken);

        return PhoneNumberInfo::fromMeta([
            'id' => $phoneId,
            'verified_name' => 'E2E Negocio',
            'quality_rating' => 'GREEN',
            'status' => 'connected',
        ]);
    }

    public function getMediaMetadata(string $accessToken, string $mediaId): MediaMetadata
    {
        $this->recordInvocation($accessToken);

        return new MediaMetadata(
            mediaId: $mediaId,
            mimeType: 'image/jpeg',
            sha256: null,
            fileSize: 0,
            url: null,
            filename: null,
        );
    }

    public function downloadMedia(string $accessToken, MediaMetadata $metadata, int $maxBytes): MediaDownload
    {
        $this->recordInvocation($accessToken);

        $buffer = fopen('php://temp', 'w+b');
        fwrite($buffer, 'fake-bytes');

        return new MediaDownload($buffer, 10, 'image/jpeg');
    }

    /**
     * @return list<TemplateInfo>
     */
    public function listTemplates(string $accessToken, string $wabaId): array
    {
        $this->recordInvocation($accessToken);

        return [];
    }

    public function subscribeToWebhooks(string $accessToken, string $wabaId): bool
    {
        $this->recordInvocation($accessToken);

        return true;
    }

    public function unsubscribeFromWebhooks(string $accessToken, string $wabaId): bool
    {
        $this->recordInvocation($accessToken);

        return true;
    }

    public function validateWebhookSignature(string $signature, string $rawBody): bool
    {
        $this->invocationCount++;

        return false;
    }

    public function verifyWebhook(array $query): array
    {
        $this->invocationCount++;

        return ['verified' => false, 'challenge' => null];
    }

    /** Número total de invocaciones del fake (todos los métodos). */
    public function invocationCount(): int
    {
        return $this->invocationCount;
    }

    /** Número de envíos de texto (sendText) realizados. */
    public function textSendCount(): int
    {
        return $this->textSendCount;
    }

    /** Marcador del tenant del último envío (token E2E sintético). */
    public function lastTenantMarker(): ?string
    {
        return $this->lastTenantMarker;
    }

    public function reset(): void
    {
        $this->textSendCount = 0;
        $this->invocationCount = 0;
        $this->lastTenantMarker = null;
    }

    private function recordInvocation(string $accessToken): void
    {
        $this->invocationCount++;
        $this->lastTenantMarker = $this->tenantMarker($accessToken);
    }

    /**
     * Deriva un identificador de tenant no sensible a partir del token sintético
     * E2E (nunca contenido real). Devuelve null si el token está vacío.
     */
    private function tenantMarker(string $accessToken): ?string
    {
        if ($accessToken === '') {
            return null;
        }

        return 'tok:'.substr(md5($accessToken), 0, 8);
    }

    private function success(string $phoneId): MessageSendResult
    {
        return MessageSendResult::success(
            providerMessageId: 'wamid-e2e-'.$phoneId.'-'.substr(bin2hex(random_bytes(8)), 0, 16),
        );
    }
}
