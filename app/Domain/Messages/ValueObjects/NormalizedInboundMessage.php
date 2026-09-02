<?php

declare(strict_types=1);

namespace App\Domain\Messages\ValueObjects;

use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Exceptions\UnsupportedMessageTypeException;

/** Canonical inbound message contract used below the WhatsApp adapter boundary. */
final readonly class NormalizedInboundMessage
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public string $providerMessageId,
        public string $sender,
        public MessageType $type,
        public ?string $providerTimestamp,
        public ?string $body,
        public ?string $mediaId,
        public ?string $mediaMime,
        public ?int $mediaSize,
        public array $metadata,
    ) {}

    /**
     * Returns null for malformed supported messages; unsupported types remain a
     * terminal domain classification for the existing webhook job.
     *
     * @param  array<string, mixed>  $eventData
     */
    public static function fromProvider(array $eventData): ?self
    {
        $providerMessageId = self::scalarString($eventData['id'] ?? null);
        $sender = self::scalarString($eventData['from'] ?? null);
        $providerType = self::scalarString($eventData['type'] ?? null);

        if ($providerMessageId === null || $sender === null || $providerType === null) {
            return null;
        }

        $type = MessageType::fromProvider($providerType);

        if ($type === null) {
            throw new UnsupportedMessageTypeException($providerType);
        }

        $providerTimestamp = self::scalarString($eventData['timestamp'] ?? null);

        return match ($type) {
            MessageType::Text => self::text($eventData, $providerMessageId, $sender, $providerTimestamp),
            MessageType::Image, MessageType::Video, MessageType::Audio, MessageType::Document => self::media($type, $eventData, $providerMessageId, $sender, $providerTimestamp),
            MessageType::Interactive => self::interactive($eventData, $providerMessageId, $sender, $providerTimestamp),
            MessageType::Location => self::location($eventData, $providerMessageId, $sender, $providerTimestamp),
            MessageType::Template => self::template($eventData, $providerMessageId, $sender, $providerTimestamp),
        };
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private static function text(array $eventData, string $id, string $sender, ?string $timestamp): ?self
    {
        $bodyValue = is_array($eventData['text'] ?? null) ? $eventData['text']['body'] ?? null : null;

        if (! is_scalar($bodyValue)) {
            return null;
        }

        $body = (string) $bodyValue;

        return new self($id, $sender, MessageType::Text, $timestamp, $body, null, null, null, []);
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private static function media(MessageType $type, array $eventData, string $id, string $sender, ?string $timestamp): self
    {
        $media = $eventData[$type->value] ?? null;

        $media = is_array($media) ? $media : [];

        $mediaId = self::scalarString($media['id'] ?? null);

        $mime = self::scalarString($media['mime_type'] ?? null);
        $sha256 = self::scalarString($media['sha256'] ?? null);
        $caption = self::scalarString($media['caption'] ?? null);
        $filename = self::scalarString($media['filename'] ?? null);
        $size = is_numeric($media['size'] ?? null) ? (int) $media['size'] : null;
        $voice = is_bool($media['voice'] ?? null) ? $media['voice'] : null;
        $metadata = array_filter([
            'id' => $mediaId,
            'mime_type' => $mime,
            'sha256' => $sha256,
            'caption' => $caption,
            'filename' => $filename,
            'size' => $size,
            'voice' => $voice,
        ], static fn (mixed $value): bool => $value !== null);

        return new self(
            $id,
            $sender,
            $type,
            $timestamp,
            $caption ?? $filename,
            $mediaId,
            $mime,
            $size,
            ['media' => $metadata],
        );
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private static function interactive(array $eventData, string $id, string $sender, ?string $timestamp): ?self
    {
        $interactive = $eventData['interactive'] ?? null;

        if (! is_array($interactive)) {
            return null;
        }

        $interactiveType = self::scalarString($interactive['type'] ?? null);

        if (! in_array($interactiveType, ['button', 'list'], true)) {
            return null;
        }

        $reply = $interactive[$interactiveType.'_reply'] ?? null;
        $reply = is_array($reply) ? $reply : null;
        $replyId = self::scalarString($reply['id'] ?? null);
        $title = self::scalarString($reply['title'] ?? null);

        if ($replyId === null || $title === null) {
            return null;
        }

        return new self(
            $id,
            $sender,
            MessageType::Interactive,
            $timestamp,
            $title,
            null,
            null,
            null,
            ['interactive' => ['type' => $interactiveType, 'id' => $replyId, 'title' => $title]],
        );
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private static function location(array $eventData, string $id, string $sender, ?string $timestamp): ?self
    {
        $location = $eventData['location'] ?? null;

        if (! is_array($location) || ! is_numeric($location['latitude'] ?? null) || ! is_numeric($location['longitude'] ?? null)) {
            return null;
        }

        $metadata = [
            'location' => array_filter([
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'name' => self::scalarString($location['name'] ?? null),
                'address' => self::scalarString($location['address'] ?? null),
            ], static fn (mixed $value): bool => $value !== null),
        ];

        return new self(
            $id,
            $sender,
            MessageType::Location,
            $timestamp,
            self::scalarString($location['address'] ?? null) ?? self::scalarString($location['name'] ?? null),
            null,
            null,
            null,
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $eventData
     */
    private static function template(array $eventData, string $id, string $sender, ?string $timestamp): self
    {
        return new self($id, $sender, MessageType::Template, $timestamp, null, null, null, null, []);
    }

    private static function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
