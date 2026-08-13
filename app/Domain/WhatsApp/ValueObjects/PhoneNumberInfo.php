<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\ValueObjects;

/**
 * Información de un número consultada a la Graph API de Meta (FASE 6).
 *
 * Se usa para validar las credenciales al conectar un número: si el token no
 * puede consultar el `phone_number_id`, la conexión se rechaza.
 */
final readonly class PhoneNumberInfo
{
    private function __construct(
        public string $id,
        public ?string $verifiedName,
        public ?string $displayPhoneNumber,
        public ?string $qualityRating,
        public ?string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data  respuesta JSON de `GET /{phone_number_id}`
     */
    public static function fromMeta(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            verifiedName: isset($data['verified_name']) ? (string) $data['verified_name'] : null,
            displayPhoneNumber: isset($data['display_phone_number']) ? (string) $data['display_phone_number'] : null,
            qualityRating: isset($data['quality_rating']) ? (string) $data['quality_rating'] : null,
            status: isset($data['status']) ? (string) $data['status'] : null,
        );
    }
}
