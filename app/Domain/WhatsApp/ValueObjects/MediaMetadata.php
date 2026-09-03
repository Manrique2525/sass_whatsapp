<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\ValueObjects;

/**
 * Metadata de un media de Meta obtenido por lookup (GET /<media_id>).
 *
 * La URL de descarga (`url`) es TEMPORAL y una detalle interno de
 * infraestructura: nunca se persiste como ubicación canónica ni se expone. El
 * lookup lo realiza SOLO el provider (nunca una URL arbitraria del cliente).
 */
final readonly class MediaMetadata
{
    public function __construct(
        public string $mediaId,
        public ?string $mimeType,
        public ?string $sha256,
        public ?int $fileSize,
        public ?string $url,
        public ?string $filename,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromProvider(string $mediaId, array $data): self
    {
        return new self(
            mediaId: $mediaId,
            mimeType: self::stringOrNull($data['mime_type'] ?? null),
            sha256: self::stringOrNull($data['sha256'] ?? null),
            fileSize: isset($data['file_size']) && is_numeric($data['file_size']) ? (int) $data['file_size'] : null,
            url: self::stringOrNull($data['url'] ?? null),
            filename: self::stringOrNull($data['filename'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
