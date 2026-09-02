<?php

declare(strict_types=1);

namespace App\Domain\Messages\ValueObjects;

use App\Domain\Messages\Enums\MessageStatus;

/** Canonical status update contract used below the WhatsApp adapter boundary. */
final readonly class NormalizedStatusUpdate
{
    /**
     * @param  array<string, string>  $failureDetails
     */
    private function __construct(
        public string $providerMessageId,
        public MessageStatus $status,
        public ?string $providerTimestamp,
        public array $failureDetails,
    ) {}

    /**
     * @param  array<string, mixed>  $eventData
     */
    public static function fromProvider(array $eventData): ?self
    {
        $providerMessageId = self::scalarString($eventData['id'] ?? null);
        $providerStatus = self::scalarString($eventData['status'] ?? null);

        if ($providerMessageId === null || $providerStatus === null) {
            return null;
        }

        $status = MessageStatus::tryFrom($providerStatus);

        if ($status === null || $status === MessageStatus::Pending || $status === MessageStatus::Sending) {
            return null;
        }

        return new self(
            $providerMessageId,
            $status,
            self::scalarString($eventData['timestamp'] ?? null),
            $status === MessageStatus::Failed ? self::failureDetails($eventData) : [],
        );
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, string>
     */
    private static function failureDetails(array $eventData): array
    {
        $errors = $eventData['errors'] ?? null;
        $error = is_array($errors) && is_array($errors[0] ?? null) ? $errors[0] : [];
        $errorData = is_array($error['error_data'] ?? null) ? $error['error_data'] : [];

        return array_filter([
            'provider_code' => self::scalarString($error['code'] ?? null),
            'title' => self::scalarString($error['title'] ?? null),
            'message' => self::scalarString($error['message'] ?? null),
            'details' => self::scalarString($errorData['details'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);
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
