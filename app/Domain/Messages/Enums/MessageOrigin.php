<?php

declare(strict_types=1);

namespace App\Domain\Messages\Enums;

/**
 * Origen interno de un outbound. Vive en `messages.metadata.origin` para poder
 * aplicar reglas de handoff sin confiar en datos enviados por el frontend.
 */
enum MessageOrigin: string
{
    case Automation = 'automation';
    case Human = 'human';
    case Handoff = 'handoff';
}
