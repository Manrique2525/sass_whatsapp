<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * El cambio de rol no está permitido por las reglas del tenant (422).
 */
final class RoleChangeNotAllowedException extends DomainException {}
