<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Exceptions;

use DomainException;

/**
 * Ya existe un tag con el mismo nombre en el tenant (409).
 */
final class TagDuplicateException extends DomainException
{
    public const ERROR_CODE = 'TAG_DUPLICATE';

    public const HTTP_STATUS = 409;

    public function __construct()
    {
        parent::__construct('Ya existe un tag con ese nombre en este tenant.');
    }
}
