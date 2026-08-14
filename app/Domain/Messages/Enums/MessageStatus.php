<?php

declare(strict_types=1);

namespace App\Domain\Messages\Enums;

/**
 * Estado de un mensaje (FASE 9, ADR-032).
 *
 * - Outbound: `pending` → `sending` (CAS por un solo worker) → `sent` →
 *   `delivered` → `read`; `failed` ante un error permanente o tras agotar los
 *   reintentos.
 *
 * `columnFor()` devuelve la columna temporal que se rellena con cada estado
 * (`sent_at`, `delivered_at`, `read_at`, `failed_at`), usada por el status job.
 */
enum MessageStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function columnFor(): ?string
    {
        return match ($this) {
            self::Sent => 'sent_at',
            self::Delivered => 'delivered_at',
            self::Read => 'read_at',
            self::Failed => 'failed_at',
            self::Pending, self::Sending => null,
        };
    }
}
