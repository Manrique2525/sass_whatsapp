<?php

declare(strict_types=1);

namespace App\Domain\Messages\Enums;

/**
 * Estado de procesamiento de un asset de media (FASE 31 U5, ADR-121).
 *
 * aplicable tanto a assets inbound (descarga desde Meta) como outbound
 * (referencia interna para envío). Se guarda como string + cast, nunca enum
 * nativo de Postgres.
 */
enum MessageMediaProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Downloaded = 'downloaded';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Downloaded || $this === self::Failed;
    }

    public function isReady(): bool
    {
        return $this === self::Downloaded;
    }
}
