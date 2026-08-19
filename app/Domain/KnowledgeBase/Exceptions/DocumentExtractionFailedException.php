<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use RuntimeException;

/**
 * La extracción de texto de un documento falló (FASE 17 U2.3).
 */
final class DocumentExtractionFailedException extends RuntimeException
{
    public const ERROR_CODE = 'DOCUMENT_EXTRACTION_FAILED';

    public const HTTP_STATUS = 422;

    public function __construct(string $reason = 'Error desconocido')
    {
        parent::__construct("Extracción de texto fallida: {$reason}.");
    }
}
