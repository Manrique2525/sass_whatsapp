<?php

declare(strict_types=1);

namespace App\Domain\Leads\Exceptions;

use DomainException;

/**
 * Ya existe un lead con datos potencialmente duplicados en el tenant (409).
 *
 * La semántica exacta de deduplicación (hard conflict vs warning)
 * se definirá en U2. Esta excepción está preparada para el caso
 * de hard conflict.
 */
final class LeadDuplicateException extends DomainException
{
    public const ERROR_CODE = 'LEAD_DUPLICATE';

    public const HTTP_STATUS = 409;

    public function __construct(string $message = 'Ya existe un lead con datos similares en este tenant.')
    {
        parent::__construct($message);
    }
}
