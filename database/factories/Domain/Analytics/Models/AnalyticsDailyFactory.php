<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Analytics\Models;

use App\Domain\Analytics\Models\AnalyticsDaily;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnalyticsDaily>
 */
final class AnalyticsDailyFactory extends Factory
{
    protected $model = AnalyticsDaily::class;

    public function definition(): array
    {
        $totalMessages = fake()->numberBetween(0, 500);
        $inbound = (int) round($totalMessages * fake()->randomFloat(2, 0.3, 0.7));
        $outbound = $totalMessages - $inbound;

        $totalConversations = fake()->numberBetween(0, 100);
        $open = fake()->numberBetween(0, (int) max(1, $totalConversations));
        $resolved = fake()->numberBetween(0, max(0, $totalConversations - $open));
        $archived = max(0, $totalConversations - $open - $resolved);

        $totalLeads = fake()->numberBetween(0, 50);
        $leadsNew = fake()->numberBetween(0, (int) max(1, $totalLeads));
        $leadsWon = fake()->numberBetween(0, max(0, (int) round($totalLeads * 0.3)));
        $leadsLost = max(0, $totalLeads - $leadsNew - $leadsWon);

        $totalFlowExecutions = fake()->numberBetween(0, 200);
        $completed = fake()->numberBetween(0, (int) max(1, $totalFlowExecutions));
        $failed = max(0, $totalFlowExecutions - $completed);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'date' => fake()->dateTimeThisYear(),
            'total_messages' => $totalMessages,
            'messages_inbound' => $inbound,
            'messages_outbound' => $outbound,
            'messages_delivered' => (int) round($outbound * fake()->randomFloat(2, 0.7, 1.0)),
            'messages_read' => (int) round($outbound * fake()->randomFloat(2, 0.3, 0.8)),
            'messages_failed' => $totalMessages - (int) round($outbound * fake()->randomFloat(2, 0.7, 1.0)) - $inbound,
            'total_conversations' => $totalConversations,
            'conversations_open' => $open,
            'conversations_resolved' => $resolved,
            'conversations_archived' => $archived,
            'conversations_handoff_requested' => fake()->numberBetween(0, (int) max(1, (int) round($totalConversations * 0.2))),
            'conversations_bot_paused' => fake()->numberBetween(0, (int) max(1, (int) round($totalConversations * 0.1))),
            'unique_contacts' => fake()->numberBetween(0, (int) max(1, (int) round($totalConversations * 1.5))),
            'avg_response_time_seconds' => fake()->optional(0.7)->numberBetween(5, 300),
            'total_flow_executions' => $totalFlowExecutions,
            'flow_executions_completed' => $completed,
            'flow_executions_failed' => $failed,
            'total_leads' => $totalLeads,
            'leads_new' => $leadsNew,
            'leads_won' => $leadsWon,
            'leads_lost' => $leadsLost,
            'total_ai_tokens' => fake()->numberBetween(0, 100000),
        ];
    }
}
