<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Exceptions;

use DomainException;

/**
 * El usuario no tiene el permiso requerido en el tenant activo (403).
 */
final class PermissionDeniedException extends DomainException {}
