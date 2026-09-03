<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/**
 * Estado de aprobación de un template (FASE 31 U5, ADR-121).
 *
 * Solo un estado `approved` permite enviar. Cualquier estado Meta desconocido o
 * no soportado se mapea a `unknown` (fallo seguro, nunca crashea el parser).
 */
enum WhatsAppTemplateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Unknown = 'unknown';

    /**
     * Mapea un status del catálogo de Meta a un status seguro conocido.
     */
    public static function fromProvider(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'approved', 'active', 'enabled' => self::Approved,
            'pending', 'in_review' => self::Pending,
            'rejected', 'disabled' => self::Rejected,
            'paused' => self::Paused,
            default => self::Unknown,
        };
    }

    public function canSend(): bool
    {
        return $this === self::Approved;
    }
}
