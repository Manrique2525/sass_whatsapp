<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Exceptions;

use DomainException;

/**
 * Ya existe un contacto activo con el mismo teléfono en el tenant (409).
 *
 * El `code` `CONTACT_DUPLICATE` permite al frontend distinguir este error de
 * otros 409 sin depender del mensaje.
 */
final class ContactDuplicateException extends DomainException
{
    public const ERROR_CODE = 'CONTACT_DUPLICATE';

    public const HTTP_STATUS = 409;

    public function __construct(string $phone)
    {
        parent::__construct("Ya existe un contacto con el teléfono {$phone} en este tenant.");
    }
}
