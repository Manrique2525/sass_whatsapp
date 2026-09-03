<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\ValueObjects;

/**
 * Resultado de la descarga de un media de Meta.
 *
 * `resource` es un stream de PHP posicionado en el inicio del contenido; la
 * aplicación lo consume una vez (lo vuelca a storage interno) y luego lo cierra.
 * `size` es el número real de bytes leídos (acotado al máximo admitido).
 * `contentType` es el Content-Type efectivo de la respuesta de descarga (si
 * existe); NUNCA se confía solo en él (la aplicación valida el contenido real).
 */
final readonly class MediaDownload
{
    /**
     * @param  resource  $resource
     */
    public function __construct(
        public mixed $resource,
        public int $size,
        public ?string $contentType,
    ) {}
}
