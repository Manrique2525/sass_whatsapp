<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * La invitación ya fue aceptada y no es reutilizable (409).
 */
final class InvitationAlreadyAcceptedException extends DomainException {}
