<?php

declare(strict_types=1);

namespace App\Domain\Messages\Enums;

/**
 * Tipos de mensaje soportados (FASE 9, ADR-032).
 *
 * Espejo de los `messages[].type` de Meta que el SaaS persiste. Tipos de Meta
 * no listados (sticker, reaction, contacts, order, system, unknown...) NO se
 * persisten como mensaje: el evento del webhook se marca `failed` con
 * `unsupported_message_type` (permanente, sin reintento) y el webhook responde
 * 200 igualmente (ver `MessageService::handleInboundMessage`).
 */
enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Document = 'document';
    case Location = 'location';
    case Interactive = 'interactive';
    case Template = 'template';

    /**
     * Mapea un `type` de Meta a un tipo soportado, o null si no se persiste.
     */
    public static function fromProvider(string $type): ?self
    {
        return self::tryFrom($type);
    }
}
