<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * La invitación ha expirado (410).
 */
final class InvitationExpiredException extends DomainException {}
