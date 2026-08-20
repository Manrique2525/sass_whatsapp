<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Exceptions;

use DomainException;

/**
 * El tag no existe o pertenece a otro tenant (se mapea a 404 por el
 * controller; el mensaje genérico oculta la existencia).
 */
final class TagNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Tag no encontrado.');
    }
}
