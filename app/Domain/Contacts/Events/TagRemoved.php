<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un tag fue removido de un contacto (FASE 20 U3).
 *
 * Se emite SOLO cuando la mutación es real (idempotente).
 * No model instances, no PII — solo IDs estables + contexto mínimo.
 * afterCommit = true: solo se dispatcha si la transacción DB fue exitosa.
 */
final class TagRemoved
{
    use Dispatchable;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $contactId,
        public readonly string $tagId,
        public readonly string $tagName,
    ) {}
}
