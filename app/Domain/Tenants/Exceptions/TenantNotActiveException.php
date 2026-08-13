<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Exceptions;

use DomainException;

/**
 * El tenant existe y el usuario es miembro, pero no está activo (p. ej.
 * suspendido). No puede usarse como tenant activo ni como destino de un switch.
 */
final class TenantNotActiveException extends DomainException {}
