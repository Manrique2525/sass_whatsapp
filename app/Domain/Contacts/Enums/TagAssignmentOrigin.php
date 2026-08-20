<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Enums;

/**
 * Origen de la asignación de un tag a un contacto (FASE 20 U3).
 *
 * Permite que U4 distinga entre assignment manual/API y assignment
 * originada por un flow (TagNodeExecutor) sin acoplar el dominio.
 */
enum TagAssignmentOrigin: string
{
    case Manual = 'manual';
    case Flow = 'flow';
}
