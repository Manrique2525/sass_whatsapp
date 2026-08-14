<?php

declare(strict_types=1);

namespace App\Domain\Flows\Enums;

/**
 * Tipo de disparo de un flujo (FASE 11, `docs/chatbot-engine.md` §6).
 *
 * FASE 11 implementa solo `keyword` / `new_message` / `start` (disparo por
 * mensaje entrante). `tag` / `schedule` / `webhook` llegan en FASE 14; sus
 * valores quedan registrados para no romper datos existentes.
 *
 * Precedencia: triggers específicos (`keyword`) antes que genéricos
 * (`new_message`/`start`). Entre `new_message` y `start`, `start` solo dispara
 * en el primer mensaje de la conversación; `new_message` en cualquier mensaje.
 */
enum FlowTriggerType: string
{
    case Keyword = 'keyword';
    case NewMessage = 'new_message';
    case Start = 'start';
    case Tag = 'tag';
    case Schedule = 'schedule';
    case Webhook = 'webhook';

    /**
     * Tipos disparados por un mensaje entrante (implementados en FASE 11).
     */
    public function isImplementedInPhaseEleven(): bool
    {
        return in_array($this, [self::Keyword, self::NewMessage, self::Start], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Keyword => 'Palabra clave',
            self::NewMessage => 'Nuevo mensaje',
            self::Start => 'Primer mensaje',
            self::Tag => 'Etiqueta',
            self::Schedule => 'Programado',
            self::Webhook => 'Webhook externo',
        };
    }
}
