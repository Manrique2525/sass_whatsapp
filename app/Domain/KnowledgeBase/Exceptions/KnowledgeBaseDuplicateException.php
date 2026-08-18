<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use DomainException;

/**
 * Ya existe una knowledge base activa con el mismo nombre en el tenant (409).
 *
 * El `code` `KB_DUPLICATE` permite al frontend distinguir este error de
 * otros 409 sin depender del mensaje.
 */
final class KnowledgeBaseDuplicateException extends DomainException
{
    public const ERROR_CODE = 'KB_DUPLICATE';

    public const HTTP_STATUS = 409;

    public function __construct(string $name)
    {
        parent::__construct("Ya existe una knowledge base con el nombre \"{$name}\" en este tenant.");
    }
}
