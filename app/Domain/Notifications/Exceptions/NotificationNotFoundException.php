<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use DomainException;

/**
 * La notificación no existe o no pertenece al usuario/tenant autorizado (404).
 */
final class NotificationNotFoundException extends DomainException {}
