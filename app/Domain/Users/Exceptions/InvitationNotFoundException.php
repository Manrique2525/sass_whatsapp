<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * La invitación no existe o su token es inválido (404).
 */
final class InvitationNotFoundException extends DomainException {}
