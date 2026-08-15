<?php

declare(strict_types=1);

namespace App\Domain\Flows\Enums;

/**
 * Tipo de disparo de un flujo (FASE 11, `docs/chatbot-engine.md` §6).
 *
 * FASE 11 implementa `keyword` / `new_message` / `start` (disparo por mensaje
 * entrante). FASE 14 (UNIDAD 1) valida y endurece la config de todos los
 * tipos; `tag` / `schedule` / `webhook` tienen otros puntos de entrada (FASE
 * 14 U2/U3 y FASE 20) y jamás matchean un mensaje entrante.
 *
 * Precedencia: triggers específicos (`keyword`) antes que genéricos
 * (`new_message`/`start`). Entre `new_message` y `start`, `start` solo dispara
 * en el primer mensaje de la conversación; `new_message` en cualquier mensaje.
 * Regla de publicación (ADR-038/039): a lo sumo un flujo publicado por tenant
 * puede tener un trigger genérico activo del mismo tipo.
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
     * Tipos disparados por un mensaje entrante (FASE 11). `tag`/`schedule`/
     * `webhook` (FASE 14) no matchean mensajes: sus puntos de entrada son el
     * etiquetado (FASE 20), el scheduler (U2) y el webhook público (U3).
     */
    public function isMessageTrigger(): bool
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
