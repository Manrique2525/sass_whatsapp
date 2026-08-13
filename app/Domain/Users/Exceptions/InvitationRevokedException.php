<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * La invitación fue revocada (410).
 */
final class InvitationRevokedException extends DomainException {}
