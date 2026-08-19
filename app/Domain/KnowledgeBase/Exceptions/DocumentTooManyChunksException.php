<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use RuntimeException;

/**
 * Un documento produce más chunks del máximo permitido (FASE 17 U2.3).
 */
final class DocumentTooManyChunksException extends RuntimeException
{
    public const ERROR_CODE = 'DOCUMENT_TOO_MANY_CHUNKS';

    public const HTTP_STATUS = 422;

    public function __construct(int $maxChunks)
    {
        parent::__construct("El documento produce más de {$maxChunks} chunks.");
    }
}
