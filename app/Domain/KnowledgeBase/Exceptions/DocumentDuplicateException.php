<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Exceptions;

use DomainException;

/**
 * Ya existe un documento activo con el mismo contenido en la KB (409).
 *
 * La deduplicación es por (tenant_id, knowledge_base_id, file_hash)
 * sobre documentos no eliminados.
 */
final class DocumentDuplicateException extends DomainException
{
    public const ERROR_CODE = 'DOCUMENT_DUPLICATE';

    public const HTTP_STATUS = 409;

    public function __construct()
    {
        parent::__construct('Ya existe un documento con el mismo contenido en esta knowledge base.');
    }
}
