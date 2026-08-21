<?php

declare(strict_types=1);

namespace App\Domain\Analytics\ValueObjects;

/**
 * Read-only snapshot of analytics overview for a date range (FASE 21 U3).
 *
 * Built by AnalyticsService from pre-computed analytics_daily rows.
 * Consumed by AnalyticsOverviewResource for JSON serialization.
 * NO PII — only aggregate counters, durations, and booleans.
 */
final readonly class AnalyticsOverview
{
    /**
     * @param  array{from: string, to: string}  $period
     * @param  array{total: int, inbound: int, outbound: int, delivered: int, read: int, failed: int}  $messages
     * @param  array{total: int, open: int, resolved: int, archived: int, handoff_requested: int, bot_paused: int, unique_contacts: int, avg_response_time_seconds: ?int}  $conversations
     * @param  array{total: int, completed: int, failed: int}  $flows
     * @param  array{total: int, new: int, won: int, lost: int}  $leads
     * @param  array{total_tokens: int}  $ai
     * @param  list<array{date: string, messages_total: int, messages_inbound: int, messages_outbound: int, conversations_total: int, leads_total: int, flow_executions_total: int, ai_tokens: int}>  $daily
     */
    public function __construct(
        public array $period,
        public array $messages,
        public array $conversations,
        public array $flows,
        public array $leads,
        public array $ai,
        public array $daily,
    ) {}

    /**
     * @return array{period: array{from: string, to: string}, messages: array<string, int>, conversations: array<string, mixed>, flows: array<string, int>, leads: array<string, int>, ai: array{total_tokens: int}, daily: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'messages' => $this->messages,
            'conversations' => $this->conversations,
            'flows' => $this->flows,
            'leads' => $this->leads,
            'ai' => $this->ai,
            'daily' => $this->daily,
        ];
    }
}
