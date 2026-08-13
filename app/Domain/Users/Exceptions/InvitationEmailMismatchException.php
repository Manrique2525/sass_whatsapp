<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * El usuario autenticado no es dueño del email de la invitación (403).
 */
final class InvitationEmailMismatchException extends DomainException {}
