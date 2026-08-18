<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use DomainException;

/**
 * El documento no existe o pertenece a otro tenant (se mapea a 404 por el
 * controller; el mensaje genérico oculta la existencia, ADR-010/023).
 */
final class DocumentNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Documento no encontrado.');
    }
}
