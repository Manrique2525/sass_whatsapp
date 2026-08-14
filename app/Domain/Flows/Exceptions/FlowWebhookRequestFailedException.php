<?php

declare(strict_types=1);

namespace App\Domain\Flows\Exceptions;

use DomainException;

/**
 * La llamada HTTP de un nodo `webhook` no tuvo éxito (timeout, red, 4xx/5xx).
 * El motor decide entre reintento (backoff) o `failed` según `attempts`.
 */
final class FlowWebhookRequestFailedException extends DomainException {}
