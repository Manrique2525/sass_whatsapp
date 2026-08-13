<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\ValueObjects;

/**
 * Resultado normalizado de un envío al provider (FASE 6).
 *
 * Normaliza la respuesta de Meta (provider_message_id, wa_id del destinatario)
 * y los errores en una forma estable para el resto de la aplicación.
 */
final readonly class MessageSendResult
{
    private function __construct(
        public bool $success,
        public ?string $providerMessageId,
        public ?string $waId,
        public ?string $providerErrorCode,
        public ?string $errorMessage,
        public bool $retryable,
    ) {}

    /**
     * @param  array<string, mixed>  $response  respuesta JSON de la Graph API
     */
    public static function success(string $providerMessageId, ?string $waId = null, array $response = []): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            waId: $waId ?? ($response['contacts'][0]['wa_id'] ?? null),
            providerErrorCode: null,
            errorMessage: null,
            retryable: false,
        );
    }

    public static function failure(?string $providerErrorCode, string $errorMessage, bool $retryable = false): self
    {
        return new self(
            success: false,
            providerMessageId: null,
            waId: null,
            providerErrorCode: $providerErrorCode,
            errorMessage: $errorMessage,
            retryable: $retryable,
        );
    }
}
