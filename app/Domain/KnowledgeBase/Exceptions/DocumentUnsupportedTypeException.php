<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use DomainException;

/**
 * Tipo de archivo no permitido (422).
 *
 * La extensión o el MIME detectado no están en la whitelist.
 */
final class DocumentUnsupportedTypeException extends DomainException
{
    public const ERROR_CODE = 'DOCUMENT_UNSUPPORTED_TYPE';

    public const HTTP_STATUS = 422;

    public function __construct(string $detected)
    {
        parent::__construct("El tipo de archivo \"{$detected}\" no está permitido.");
    }
}
