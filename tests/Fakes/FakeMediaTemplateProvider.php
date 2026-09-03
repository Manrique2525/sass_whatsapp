<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Messages\Enums\MessageMediaFailureReason;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Exceptions\WhatsAppMediaDownloadException;
use App\Domain\WhatsApp\ValueObjects\InteractiveMessage;
use App\Domain\WhatsApp\ValueObjects\MediaDownload;
use App\Domain\WhatsApp\ValueObjects\MediaMetadata;
use App\Domain\WhatsApp\ValueObjects\MessageSendResult;
use App\Domain\WhatsApp\ValueObjects\PhoneNumberInfo;
use App\Domain\WhatsApp\ValueObjects\TemplateInfo;

/**
 * Fake del provider SOLO para tests de media/templates (FASE 31 U5, ADR-121).
 *
 * - `getMediaMetadata`/`downloadMedia` devuelven contenido configurable.
 * - `listTemplates` devuelve el catálogo configurable.
 * - `sendTemplate`/`sendText` NO hacen red; incrementan contadores para
 *   poder asertar "0 llamadas a Meta" en los rechazos.
 */
final class FakeMediaTemplateProvider implements WhatsAppProviderInterface
{
    private int $sendTextCalls = 0;

    private int $sendTemplateCalls = 0;

    private int $downloadCalls = 0;

    private ?MediaMetadata $downloadMetadata = null;

    private string $downloadBytes = '';

    private ?string $downloadContentType = null;

    /** @var list<TemplateInfo> */
    private array $templateCatalog = [];

    public const VALID_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0dIDATh\x89c\x63\x60\x00\x00\x00\x02\x00\x01\xe2\x21\xbc\x33\x00\x00\x00\x00IEND\xaeB`\x82";

    public ?string $lastTemplateName = null;

    public ?string $lastTemplateLanguage = null;

    /** @var list<array<string, string>> */
    public array $lastTemplateParams = [];

    public function setDownload(MediaMetadata $metadata, string $bytes = self::VALID_PNG, ?string $contentType = 'image/png'): void
    {
        $this->downloadMetadata = $metadata;
        $this->downloadBytes = $bytes;
        $this->downloadContentType = $contentType;
    }

    /**
     * @param  list<TemplateInfo>  $catalog
     */
    public function setTemplateCatalog(array $catalog): void
    {
        $this->templateCatalog = $catalog;
    }

    public function downloadCalls(): int
    {
        return $this->downloadCalls;
    }

    public function sendTemplateCalls(): int
    {
        return $this->sendTemplateCalls;
    }

    public function sendTextCalls(): int
    {
        return $this->sendTextCalls;
    }

    public function sendText(string $accessToken, string $phoneId, string $to, string $text, array $context = []): MessageSendResult
    {
        $this->sendTextCalls++;

        return $this->success();
    }

    public function sendTemplate(string $accessToken, string $phoneId, string $to, string $templateName, string $language, array $params = []): MessageSendResult
    {
        $this->sendTemplateCalls++;

        $this->lastTemplateName = $templateName;
        $this->lastTemplateLanguage = $language;
        $this->lastTemplateParams = $params;

        return $this->success();
    }

    public function sendImage(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $caption = ''): MessageSendResult
    {
        return $this->success();
    }

    public function sendDocument(string $accessToken, string $phoneId, string $to, string $mediaUrl, string $filename = ''): MessageSendResult
    {
        return $this->success();
    }

    public function sendInteractiveMessage(string $accessToken, string $phoneId, string $to, InteractiveMessage $message): MessageSendResult
    {
        return $this->success();
    }

    public function markAsRead(string $accessToken, string $phoneId, string $messageId): void {}

    public function getPhoneNumberInfo(string $accessToken, string $phoneId): PhoneNumberInfo
    {
        return PhoneNumberInfo::fromMeta([
            'id' => $phoneId,
            'verified_name' => 'Negocio',
            'quality_rating' => 'GREEN',
            'status' => 'connected',
        ]);
    }

    public function getMediaMetadata(string $accessToken, string $mediaId): MediaMetadata
    {
        if ($this->downloadMetadata === null) {
            return new MediaMetadata($mediaId, 'image/png', null, 0, null, null);
        }

        return $this->downloadMetadata;
    }

    public function downloadMedia(string $accessToken, MediaMetadata $metadata, int $maxBytes): MediaDownload
    {
        $this->downloadCalls++;

        $bytes = $this->downloadBytes !== '' ? $this->downloadBytes : self::VALID_PNG;

        if (strlen($bytes) > $maxBytes) {
            throw new WhatsAppMediaDownloadException(
                'oversize',
                MessageMediaFailureReason::Oversize,
            );
        }

        $buffer = fopen('php://temp', 'w+b');
        fwrite($buffer, $bytes);
        rewind($buffer);

        return new MediaDownload($buffer, strlen($bytes), $this->downloadContentType);
    }

    public function listTemplates(string $accessToken, string $wabaId): array
    {
        return $this->templateCatalog;
    }

    public function subscribeToWebhooks(string $accessToken, string $wabaId): bool
    {
        return true;
    }

    public function unsubscribeFromWebhooks(string $accessToken, string $wabaId): bool
    {
        return true;
    }

    public function validateWebhookSignature(string $signature, string $rawBody): bool
    {
        return false;
    }

    public function verifyWebhook(array $query): array
    {
        return ['verified' => false, 'challenge' => null];
    }

    private function success(): MessageSendResult
    {
        return MessageSendResult::success(
            providerMessageId: 'wamid-test-'.substr(bin2hex(random_bytes(8)), 0, 16),
        );
    }
}
