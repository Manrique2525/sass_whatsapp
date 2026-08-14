<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Enums;

/**
 * Estado de una conversación (FASE 8, ADR-031).
 *
 * Máquina de estados:
 *
 * - `open` ↔ `pending` (idle sin respuesta del cliente, o esperando al bot).
 * - `open`/`pending` → `resolved` (resuelta/cerrada).
 * - `resolved` → `archived` (solo se archiva una conversación resuelta).
 * - cualquier estado ≠ `open` → `open` (reabrir).
 *
 * `canTransitionTo()` valida las transiciones; un PATCH con el mismo estado es
 * no-op (200). Un `close()` sobre una conversación ya resuelta es no-op; sobre
 * una archivada es un error 409.
 */
enum ConversationStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Archived = 'archived';

    /**
     * Estados a los que se puede transicionar directamente.
     *
     * @return list<ConversationStatus>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::Pending, self::Resolved],
            self::Pending => [self::Open, self::Resolved],
            self::Resolved => [self::Open, self::Archived],
            self::Archived => [self::Open],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->transitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Pending => 'Pendiente',
            self::Resolved => 'Resuelta',
            self::Archived => 'Archivada',
        };
    }
}
