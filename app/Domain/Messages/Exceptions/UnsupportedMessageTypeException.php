<?php

declare(strict_types=1);

namespace App\Domain\Messages\Exceptions;

use RuntimeException;

/**
 * Un mensaje entrante es de un tipo de Meta que el SaaS no persiste
 * (sticker, reaction, contacts, order, system, unknown...).
 *
 * No es un error: el webhook responde 200 igualmente y el evento se marca
 * `failed` con `unsupported_message_type` (permanente, sin reintento). No se
 * crea fila en `messages`.
 */
final class UnsupportedMessageTypeException extends RuntimeException
{
    public function __construct(string $providerType = '')
    {
        $detail = $providerType === '' ? '' : " ({$providerType})";
        parent::__construct("Tipo de mensaje no soportado{$detail}.");
    }
}
