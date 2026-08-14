<?php

declare(strict_types=1);

namespace App\Domain\Flows\Enums;

/**
 * Estado de un flujo (FASE 11, ADR-034).
 *
 * No existe tabla `flow_versions`: la propia fila de `flows` es la versión
 * (solo el flujo `published` se ejecuta; `draft`/`inactive` no disparan).
 *
 * Máquina de estados:
 * - `draft` → `published` (publicar); `draft` → `inactive` (descartar).
 * - `published` → `inactive` (desactivar); `published` → `draft` (volver a editar).
 * - `inactive` → `draft` (volver a editar); `inactive` → `published` (republcar).
 *
 * `canTransitionTo()` valida las transiciones; un PATCH con el mismo estado es
 * no-op (200).
 */
enum FlowStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Inactive = 'inactive';

    /**
     * Estados a los que se puede transicionar directamente.
     *
     * @return list<FlowStatus>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Inactive],
            self::Published => [self::Inactive, self::Draft],
            self::Inactive => [self::Draft, self::Published],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->transitions(), true);
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicado',
            self::Inactive => 'Inactivo',
        };
    }
}
