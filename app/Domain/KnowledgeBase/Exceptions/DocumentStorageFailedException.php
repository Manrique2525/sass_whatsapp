<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use RuntimeException;

/**
 * Fallo al escribir/leer el archivo fuente en el storage (500 seguro).
 *
 * No expone: endpoint, credentials, bucket, path ni stack trace.
 */
final class DocumentStorageFailedException extends RuntimeException
{
    public const ERROR_CODE = 'DOCUMENT_STORAGE_FAILED';

    public const HTTP_STATUS = 500;

    public function __construct(?string $detail = null)
    {
        $message = 'No se pudo almacenar el documento.';

        if ($detail !== null) {
            $message .= ' '.$detail;
        }

        parent::__construct($message);
    }
}
