<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\NotificationPriority;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
final class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'tenant_id' => (string) Str::uuid(),
            'user_id' => null,
            'type' => NotificationType::System,
            'priority' => NotificationPriority::Normal,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(1),
            'data' => null,
            'read_at' => null,
        ];
    }

    public function unread(): static
    {
        return $this->state(fn () => ['read_at' => null]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()->subMinutes(random_int(1, 60))]);
    }

    public function highPriority(): static
    {
        return $this->state(fn () => ['priority' => NotificationPriority::High]);
    }

    public function lowPriority(): static
    {
        return $this->state(fn () => ['priority' => NotificationPriority::Low]);
    }

    public function handoffRequested(): static
    {
        return $this->state(fn () => ['type' => NotificationType::HandoffRequested]);
    }

    public function conversationAssigned(): static
    {
        return $this->state(fn () => ['type' => NotificationType::ConversationAssigned]);
    }

    public function conversationClaimed(): static
    {
        return $this->state(fn () => ['type' => NotificationType::ConversationClaimed]);
    }

    public function tenantWide(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    public function targeted(): static
    {
        return $this->state(fn () => ['user_id' => fake()->unique()->randomNumber()]);
    }

    public function withData(array $data): static
    {
        return $this->state(fn () => ['data' => $data]);
    }
}
