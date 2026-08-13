<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * La invitación ya no está en estado pendiente (409).
 */
final class InvitationNotPendingException extends DomainException {}
