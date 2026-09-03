<?php

declare(strict_types=1);

namespace App\Domain\Messages\Enums;

/**
 * Código de fallo seguro de un asset de media (FASE 31 U5, ADR-121).
 *
 * Es un código MACHINE-READABLE, nunca mensaje crudo ni contenido. La
 * terminología es consistente con la policy de procesamiento.
 */
enum MessageMediaFailureReason: string
{
    case Oversize = 'oversize';
    case InvalidMime = 'invalid_mime';
    case SsrfRejected = 'ssrf_rejected';
    case DownloadFailed = 'download_failed';
    case ProviderNotFound = 'provider_not_found';
    case StorageFailed = 'storage_failed';
}
