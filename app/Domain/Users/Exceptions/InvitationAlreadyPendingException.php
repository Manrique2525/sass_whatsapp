<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * Ya existe una invitación pendiente para ese email en el tenant (409).
 */
final class InvitationAlreadyPendingException extends DomainException {}
