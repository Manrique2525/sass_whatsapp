<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use RuntimeException;

/**
 * El texto extraído de un documento excede el límite máximo (FASE 17 U2.3).
 */
final class DocumentTextTooLargeException extends RuntimeException
{
    public const ERROR_CODE = 'DOCUMENT_TEXT_TOO_LARGE';

    public const HTTP_STATUS = 422;

    public function __construct(int $maxSize)
    {
        parent::__construct("El texto extraído excede el límite de {$maxSize} caracteres.");
    }
}
