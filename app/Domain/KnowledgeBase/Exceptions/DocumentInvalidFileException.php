<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use DomainException;

/**
 * El archivo subido no supera la validación de seguridad (422).
 *
 * Cubre: magic bytes inválidos, MIME mismatch, archivo vacío,
 * binario disfrazado, DOCX no válido, filename peligroso.
 */
final class DocumentInvalidFileException extends DomainException
{
    public const ERROR_CODE = 'DOCUMENT_INVALID_FILE';

    public const HTTP_STATUS = 422;

    public function __construct(string $reason)
    {
        parent::__construct("El archivo no es válido: {$reason}.");
    }
}
