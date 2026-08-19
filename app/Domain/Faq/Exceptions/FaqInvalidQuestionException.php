<?php

declare(strict_types=1);

namespace App\Domain\Faq\Exceptions;

use DomainException;

/**
 * La pregunta normaliza a vacío después de trim + edge punctuation + whitespace collapse (422).
 */
final class FaqInvalidQuestionException extends DomainException
{
    public const ERROR_CODE = 'FAQ_INVALID_QUESTION';

    public const HTTP_STATUS = 422;

    public function __construct()
    {
        parent::__construct('La pregunta normalizada está vacía. Ingrese contenido válido.');
    }
}
