<?php

declare(strict_types=1);

namespace App\Domain\Faq\Exceptions;

use DomainException;

/**
 * Ya existe una FAQ activa con la misma pregunta normalizada en el tenant (409).
 */
final class FaqDuplicateException extends DomainException
{
    public const ERROR_CODE = 'FAQ_DUPLICATE';

    public const HTTP_STATUS = 409;

    public function __construct()
    {
        parent::__construct('Ya existe una FAQ con la misma pregunta normalizada en este tenant.');
    }
}
