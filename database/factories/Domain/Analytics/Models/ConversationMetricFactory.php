<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Analytics\Models;

use App\Domain\Analytics\Models\ConversationMetric;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConversationMetric>
 */
final class ConversationMetricFactory extends Factory
{
    protected $model = ConversationMetric::class;

    public function definition(): array
    {
        $messageCount = fake()->numberBetween(1, 50);
        $botMessages = fake()->numberBetween(0, (int) max(1, (int) round($messageCount * 0.6)));
        $agentMessages = max(0, $messageCount - $botMessages);

        $createdAt = fake()->dateTimeThisYear();
        $lastMessageAt = (clone $createdAt)->modify('+'.fake()->numberBetween(1, 7200).' seconds');

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'conversation_id' => (string) Str::uuid(),
            'first_response_at' => fake()->optional(0.8)->dateTimeBetween($createdAt, $lastMessageAt),
            'last_message_at' => $lastMessageAt,
            'resolved_at' => fake()->optional(0.5)->dateTimeBetween($lastMessageAt, '+7 days'),
            'response_time_seconds' => fake()->optional(0.7)->numberBetween(5, 600),
            'handle_time_seconds' => fake()->optional(0.5)->numberBetween(30, 7200),
            'message_count' => $messageCount,
            'bot_message_count' => $botMessages,
            'agent_message_count' => $agentMessages,
            'handoff_requested' => fake()->boolean(20),
            'handoff_at' => fake()->optional(0.3)->dateTimeBetween($createdAt, $lastMessageAt),
        ];
    }
}
