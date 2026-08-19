<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use RuntimeException;

/**
 * Excepción para intentar eliminar/actuar sobre un documento que está
 * siendo procesado por un worker (FASE 17 U2.4).
 */
final class DocumentProcessingException extends RuntimeException
{
    public const string ERROR_CODE = 'DOCUMENT_PROCESSING';

    public function __construct()
    {
        parent::__construct('El documento está siendo procesado. Intente de nuevo más tarde.');
    }
}
