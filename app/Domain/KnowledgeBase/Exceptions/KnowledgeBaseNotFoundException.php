<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use DomainException;

/**
 * La knowledge base no existe o pertenece a otro tenant (se mapea a 404 por el
 * controller; el mensaje genérico oculta la existencia, ADR-010/023).
 */
final class KnowledgeBaseNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Knowledge base no encontrada.');
    }
}
