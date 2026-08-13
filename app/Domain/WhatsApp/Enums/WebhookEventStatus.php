<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Estados del ciclo de vida de un evento de webhook (FASE 6, outbox).
 *
 * received → enqueued → processed. `failed` marca eventos no resolubles
 * (p. ej. phone_number_id desconocido) o erróneos; el sweeper re-encola
 * los que quedaron en `received` (comando programado, docs/whatsapp.md §4.3).
 */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Enqueued = 'enqueued';
    case Processed = 'processed';
    case Failed = 'failed';
}
