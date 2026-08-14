<?php

declare(strict_types=1);

namespace App\Domain\Messages\ValueObjects;

use App\Domain\Messages\Models\Message;

/**
 * Resultado del procesamiento de un inbound (FASE 11, ADR-037).
 *
 * El webhook necesita saber si el mensaje se PERSISTIÓ ahora (`created`) o si
 * ya existía (dedupe por `provider_message_id`) para acusar de forma
 * idempotente. El motor de flujos usa `message` para decidir trigger/resume y
 * su propia barrera de idempotencia (`last_inbound_message_id`).
 */
final readonly class InboundMessageResult
{
    private function __construct(
        public ?Message $message,
        public bool $created,
    ) {}

    public static function unprocessable(): self
    {
        return new self(message: null, created: false);
    }

    public static function existing(Message $message): self
    {
        return new self(message: $message, created: false);
    }

    public static function created(Message $message): self
    {
        return new self(message: $message, created: true);
    }
}
