<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\ValueObjects;

/**
 * Template del catálogo de Meta normalizado (FASE 31 U5, ADR-121).
 *
 * Campos desconocidos se ignoran de forma segura; `components` se normaliza a
 * tipos canónicos (HEADER/BODY/FOOTER/BUTTONS) sin estructura ejecutable.
 *
 * @param  list<array<string, mixed>>  $components
 */
final readonly class TemplateInfo
{
    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function __construct(
        public string $name,
        public string $language,
        public ?string $category,
        public string $status,
        public ?string $providerTemplateId,
        public array $components,
    ) {}

    /**
     * Desnormaliza una entrada del catálogo de Meta (`message_templates.data[]`).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromProvider(array $data): ?self
    {
        $name = self::stringOrNull($data['name'] ?? null);
        $language = self::stringOrNull($data['language'] ?? null);

        if ($name === null || $language === null) {
            return null;
        }

        $category = self::stringOrNull($data['category'] ?? null);
        $status = self::stringOrNull($data['status'] ?? null) ?? 'unknown';
        $providerId = self::stringOrNull($data['id'] ?? null);

        $components = [];
        if (isset($data['components']) && is_array($data['components'])) {
            foreach ($data['components'] as $component) {
                if (! is_array($component)) {
                    continue;
                }

                $type = self::stringOrNull($component['type'] ?? null);
                if ($type === null) {
                    continue;
                }

                $components[] = self::normalizeComponent($type, $component);
            }
        }

        return new self($name, $language, $category, $status, $providerId, $components);
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array<string, mixed>
     */
    private static function normalizeComponent(string $type, array $component): array
    {
        $normalized = ['type' => $type];

        if (isset($component['text']) && is_scalar($component['text'])) {
            $normalized['text'] = (string) $component['text'];
        }

        if ($type === 'BUTTONS' && isset($component['buttons']) && is_array($component['buttons'])) {
            $normalized['buttons'] = array_values(array_filter(
                array_map(
                    static fn (mixed $button): ?array => is_array($button)
                        ? self::normalizeButton($button)
                        : null,
                    $component['buttons'],
                ),
                static fn (?array $button): bool => $button !== null,
            ));
        }

        if (isset($component['example']) && is_array($component['example'])) {
            $normalized['example'] = $component['example'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $button
     * @return array<string, mixed>|null
     */
    private static function normalizeButton(array $button): ?array
    {
        $type = self::stringOrNull($button['type'] ?? null);
        if ($type === null) {
            return null;
        }

        $normalized = ['type' => $type];

        if (isset($button['text']) && is_scalar($button['text'])) {
            $normalized['text'] = (string) $button['text'];
        }

        if (isset($button['url']) && is_scalar($button['url'])) {
            $normalized['url'] = (string) $button['url'];
        }

        if (isset($button['phone_number']) && is_scalar($button['phone_number'])) {
            $normalized['phone_number'] = (string) $button['phone_number'];
        }

        return $normalized;
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
