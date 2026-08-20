<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Events;

use App\Domain\Contacts\Enums\TagAssignmentOrigin;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un tag fue asignado a un contacto por primera vez (FASE 20 U3).
 *
 * Se emite SOLO cuando la mutación es real (idempotente).
 * No model instances, no PII — solo IDs estables + contexto mínimo.
 *
 * afterCommit = true: solo se dispatcha si la transacción DB fue exitosa.
 * U4 escuchará este evento para decidir si inicia un flow.
 */
final class TagAssigned
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $contactId,
        public readonly string $tagId,
        public readonly string $tagName,
        public readonly TagAssignmentOrigin $origin,
        public readonly ?string $conversationId = null,
        public readonly ?string $originExecutionId = null,
    ) {}
}
