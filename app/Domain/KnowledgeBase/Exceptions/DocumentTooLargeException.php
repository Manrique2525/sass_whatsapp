<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use DomainException;

/**
 * El archivo excede el límite de tamaño permitido (413).
 */
final class DocumentTooLargeException extends DomainException
{
    public const ERROR_CODE = 'DOCUMENT_TOO_LARGE';

    public const HTTP_STATUS = 413;

    public function __construct(int $maxBytes)
    {
        parent::__construct("El archivo excede el límite de {$maxBytes} bytes.");
    }
}
