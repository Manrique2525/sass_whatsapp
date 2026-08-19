<?php

declare(strict_types=1);

namespace App\Domain\Faq\Exceptions;

use DomainException;

/**
 * La FAQ no existe o pertenece a otro tenant (se mapea a 404 por el
 * controller; el mensaje genérico oculta la existencia).
 */
final class FaqNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('FAQ no encontrada.');
    }
}
