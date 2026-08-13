<?php

declare(strict_types=1);

namespace App\Domain\Users\Exceptions;

use DomainException;

/**
 * El usuario ya es miembro del tenant (422).
 */
final class MemberAlreadyExistsException extends DomainException {}
