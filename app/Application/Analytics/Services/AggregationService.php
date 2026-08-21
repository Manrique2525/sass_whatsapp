<?php

declare(strict_types=1);

namespace App\Application\Analytics\Services;

use App\Domain\Analytics\Models\AnalyticsDaily;
use App\Domain\Analytics\Models\ConversationMetric;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\Exceptions\InvalidTimeZoneException;
use Illuminate\Support\Carbon as SupportCarbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materializes daily analytics aggregates from raw transactional data.
 *
 * Timezone contract (ADR-078):
 * - analytics_daily.date = tenant-local calendar day.
 * - Window boundaries are computed in tenant timezone, then converted to UTC
 *   for DB queries. All message/flow/lead timestamps are stored as UTC.
 *
 * Message counting semantics:
 * - total_messages = COUNT(messages) WHERE created_at ∈ window.
 * - delivered/read/failed = lifecycle timestamps set within window (may overlap).
 *
 * Conversation counting semantics:
 * - total_conversations = conversations CREATED during window.
 * - Status counts = current snapshot of those conversations.
 *
 * Response time semantics:
 * - Per-conversation: first outbound message - first inbound message (seconds).
 * - analytics_daily.avg = AVG across active conversations (NULL excluded).
 * - Negative values treated as NULL.
 *
 * Lead metric limitation (ADR-078):
 * - Status counts are current snapshots (no transition history).
 * - won/lost may not reflect the day the transition occurred.
 *
 * Cache: DEFERRED to U3. No cache reads/writes here.
 */
final class AggregationService
{
    private const MAX_RANGE_DAYS = 365;

    /**
     * Aggregate analytics for a single tenant and date.
     *
     * Idempotent: re-running for the same (tenant, date) replaces previous aggregates.
     *
     * @throws InvalidTimeZoneException
     */
    public function aggregateForDate(Tenant $tenant, string $date): AnalyticsDaily
    {
        $tenantId = $tenant->id;
        $timezone = $this->resolveTimezone($tenant);
        $start = SupportCarbon::parse($date, $timezone)->startOfDay();
        $end = $start->copy()->addDay();

        $window = [
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
        ];

        $messageMetrics = $this->computeMessageMetrics($tenantId, $window);
        $conversationCounts = $this->computeConversationCounts($tenantId, $window);
        $uniqueContacts = $this->computeUniqueContacts($tenantId, $window);
        $flowMetrics = $this->computeFlowMetrics($tenantId, $window);
        $leadMetrics = $this->computeLeadMetrics($tenantId, $window);
        $aiTokens = $this->computeAiTokens($tenantId, $window);
        $conversationMetricsData = $this->computeConversationMetrics($tenantId, $window);

        $daily = null;

        $previousTenantId = TenantContext::id();
        TenantContext::setId($tenantId);

        try {
            DB::transaction(function () use (
                $tenantId,
                $date,
                $messageMetrics,
                $conversationCounts,
                $uniqueContacts,
                $flowMetrics,
                $leadMetrics,
                $aiTokens,
                $conversationMetricsData,
                &$daily,
            ): void {
                foreach ($conversationMetricsData as $cmData) {
                    ConversationMetric::updateOrCreate(
                        ['tenant_id' => $tenantId, 'conversation_id' => $cmData['conversation_id']],
                        collect($cmData)->except('conversation_id')->toArray(),
                    );
                }

                $avgResponseTime = $this->computeAvgResponseTime(
                    $tenantId,
                    $conversationMetricsData,
                );

                $now = now()->toDateTimeString();
                $payload = [
                    'total_messages' => $messageMetrics['total'],
                    'messages_inbound' => $messageMetrics['inbound'],
                    'messages_outbound' => $messageMetrics['outbound'],
                    'messages_delivered' => $messageMetrics['delivered'],
                    'messages_read' => $messageMetrics['read'],
                    'messages_failed' => $messageMetrics['failed'],
                    'total_conversations' => $conversationCounts['total'],
                    'conversations_open' => $conversationCounts['open'],
                    'conversations_resolved' => $conversationCounts['resolved'],
                    'conversations_archived' => $conversationCounts['archived'],
                    'conversations_handoff_requested' => $conversationCounts['handoff'],
                    'conversations_bot_paused' => $conversationCounts['bot_paused'],
                    'unique_contacts' => $uniqueContacts,
                    'avg_response_time_seconds' => $avgResponseTime,
                    'total_flow_executions' => $flowMetrics['total'],
                    'flow_executions_completed' => $flowMetrics['completed'],
                    'flow_executions_failed' => $flowMetrics['failed'],
                    'total_leads' => $leadMetrics['total'],
                    'leads_new' => $leadMetrics['new'],
                    'leads_won' => $leadMetrics['won'],
                    'leads_lost' => $leadMetrics['lost'],
                    'total_ai_tokens' => $aiTokens,
                    'updated_at' => $now,
                ];

                $existing = DB::table('analytics_daily')
                    ->where('tenant_id', $tenantId)
                    ->where('date', $date)
                    ->first();

                if ($existing !== null) {
                    DB::table('analytics_daily')
                        ->where('id', $existing->id)
                        ->update($payload);
                    $daily = AnalyticsDaily::withoutTenantScope()->find($existing->id);
                } else {
                    $id = (string) Str::uuid();
                    DB::table('analytics_daily')->insert(array_merge($payload, [
                        'id' => $id,
                        'tenant_id' => $tenantId,
                        'date' => $date,
                        'created_at' => $now,
                    ]));
                    $daily = AnalyticsDaily::withoutTenantScope()->find($id);
                }
            });
        } finally {
            if ($previousTenantId !== null) {
                TenantContext::setId($previousTenantId);
            }
        }

        return $daily ?? AnalyticsDaily::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('date', $date)
            ->firstOrFail();
    }

    /**
     * Aggregate analytics for a tenant across a date range.
     *
     * @return Collection<int, AnalyticsDaily>
     */
    public function aggregateForTenant(
        string $tenantId,
        string $from,
        string $to,
    ): Collection {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return collect();
        }

        $timezone = $this->resolveTimezone($tenant);
        $current = SupportCarbon::parse($from, $timezone)->startOfDay();
        $end = SupportCarbon::parse($to, $timezone)->startOfDay();
        $days = (int) $current->diffInDays($end) + 1;

        if ($days > self::MAX_RANGE_DAYS) {
            $end = $current->copy()->addDays(self::MAX_RANGE_DAYS - 1);
        }

        $results = collect();

        while ($current->lte($end)) {
            $results->push($this->aggregateForDate($tenant, $current->toDateString()));
            $current->addDay();
        }

        return $results;
    }

    private function resolveTimezone(Tenant $tenant): string
    {
        $tz = $tenant->timezone ?? config('app.timezone', 'UTC');

        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            $tz = 'UTC';
        }

        return $tz;
    }

    /**
     * @param  array{start: string, end: string}  $window
     * @return array{total: int, inbound: int, outbound: int, delivered: int, read: int, failed: int}
     */
    private function computeMessageMetrics(string $tenantId, array $window): array
    {
        $row = DB::selectOne(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN direction = :dir_in THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction = :dir_out THEN 1 ELSE 0 END) as outbound,
                SUM(CASE WHEN delivered_at IS NOT NULL AND delivered_at >= :start AND delivered_at < :end THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN read_at IS NOT NULL AND read_at >= :start AND read_at < :end THEN 1 ELSE 0 END) as read_count,
                SUM(CASE WHEN failed_at IS NOT NULL AND failed_at >= :start AND failed_at < :end THEN 1 ELSE 0 END) as failed
            FROM messages
            WHERE tenant_id = :tid AND created_at >= :start AND created_at < :end',
            [
                'tid' => $tenantId,
                'start' => $window['start'],
                'end' => $window['end'],
                'dir_in' => 'inbound',
                'dir_out' => 'outbound',
            ],
        );

        return [
            'total' => (int) ($row->total ?? 0),
            'inbound' => (int) ($row->inbound ?? 0),
            'outbound' => (int) ($row->outbound ?? 0),
            'delivered' => (int) ($row->delivered ?? 0),
            'read' => (int) ($row->read_count ?? 0),
            'failed' => (int) ($row->failed ?? 0),
        ];
    }

    /**
     * @param  array{start: string, end: string}  $window
     * @return array{total: int, open: int, resolved: int, archived: int, handoff: int, bot_paused: int}
     */
    private function computeConversationCounts(string $tenantId, array $window): array
    {
        $row = DB::selectOne(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status IN (:s_open, :s_pending) THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN status = :s_resolved THEN 1 ELSE 0 END) as resolved_count,
                SUM(CASE WHEN status = :s_archived THEN 1 ELSE 0 END) as archived_count,
                SUM(CASE WHEN handoff_requested_at IS NOT NULL THEN 1 ELSE 0 END) as handoff_count,
                SUM(CASE WHEN bot_paused = :bp THEN 1 ELSE 0 END) as bot_paused_count
            FROM conversations
            WHERE tenant_id = :tid
              AND created_at >= :start AND created_at < :end
              AND deleted_at IS NULL',
            [
                'tid' => $tenantId,
                'start' => $window['start'],
                'end' => $window['end'],
                's_open' => 'open',
                's_pending' => 'pending',
                's_resolved' => 'resolved',
                's_archived' => 'archived',
                'bp' => true,
            ],
        );

        return [
            'total' => (int) ($row->total ?? 0),
            'open' => (int) ($row->open_count ?? 0),
            'resolved' => (int) ($row->resolved_count ?? 0),
            'archived' => (int) ($row->archived_count ?? 0),
            'handoff' => (int) ($row->handoff_count ?? 0),
            'bot_paused' => (int) ($row->bot_paused_count ?? 0),
        ];
    }

    /** @param array{start: string, end: string} $window */
    private function computeUniqueContacts(string $tenantId, array $window): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(DISTINCT c.contact_id) as cnt
            FROM conversations c
            INNER JOIN (
                SELECT DISTINCT conversation_id
                FROM messages
                WHERE tenant_id = :tid AND created_at >= :start AND created_at < :end
            ) m ON c.id = m.conversation_id
            WHERE c.tenant_id = :tid AND c.deleted_at IS NULL',
            [
                'tid' => $tenantId,
                'start' => $window['start'],
                'end' => $window['end'],
            ],
        );

        return (int) ($row->cnt ?? 0);
    }

    /**
     * @param  array{start: string, end: string}  $window
     * @return array{total: int, completed: int, failed: int}
     */
    private function computeFlowMetrics(string $tenantId, array $window): array
    {
        $row = DB::selectOne(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = :s_completed THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = :s_failed THEN 1 ELSE 0 END) as failed
            FROM flow_executions
            WHERE tenant_id = :tid AND created_at >= :start AND created_at < :end',
            [
                'tid' => $tenantId,
                'start' => $window['start'],
                'end' => $window['end'],
                's_completed' => 'completed',
                's_failed' => 'failed',
            ],
        );

        return [
            'total' => (int) ($row->total ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'failed' => (int) ($row->failed ?? 0),
        ];
    }

    /**
     * @param  array{start: string, end: string}  $window
     * @return array{total: int, new: int, won: int, lost: int}
     */
    private function computeLeadMetrics(string $tenantId, array $window): array
    {
        $row = DB::selectOne(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = :s_new THEN 1 ELSE 0 END) as new_count,
                SUM(CASE WHEN status = :s_won THEN 1 ELSE 0 END) as won_count,
                SUM(CASE WHEN status = :s_lost THEN 1 ELSE 0 END) as lost_count
            FROM leads
            WHERE tenant_id = :tid AND created_at >= :start AND created_at < :end AND deleted_at IS NULL',
            [
                'tid' => $tenantId,
                'start' => $window['start'],
                'end' => $window['end'],
                's_new' => 'new',
                's_won' => 'won',
                's_lost' => 'lost',
            ],
        );

        return [
            'total' => (int) ($row->total ?? 0),
            'new' => (int) ($row->new_count ?? 0),
            'won' => (int) ($row->won_count ?? 0),
            'lost' => (int) ($row->lost_count ?? 0),
        ];
    }

    /** @param array{start: string, end: string} $window */
    private function computeAiTokens(string $tenantId, array $window): int
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(CAST(payload->>'total_tokens' AS BIGINT)), 0) as total
            FROM flow_execution_logs
            WHERE tenant_id = :tid
              AND event = 'ai_completed'
              AND created_at >= :start AND created_at < :end",
            [
                'tid' => $tenantId,
                'start' => $window['start'],
                'end' => $window['end'],
            ],
        );

        return (int) ($row->total ?? 0);
    }

    /**
     * @param  array{start: string, end: string}  $window
     * @return list<array{conversation_id: string, first_response_at: ?string, last_message_at: ?string, resolved_at: null, response_time_seconds: ?int, handle_time_seconds: null, message_count: int, bot_message_count: int, agent_message_count: int, handoff_requested: bool, handoff_at: ?string}>
     */
    private function computeConversationMetrics(string $tenantId, array $window): array
    {
        $messageStats = DB::select(
            'SELECT
                conversation_id,
                MIN(CASE WHEN direction = :dir_out THEN created_at END) as first_outbound_at,
                MIN(CASE WHEN direction = :dir_in THEN created_at END) as first_inbound_at,
                MAX(created_at) as last_message_at,
                COUNT(*) as message_count,
                SUM(CASE WHEN direction = :dir_out AND sent_by_user_id IS NULL THEN 1 ELSE 0 END) as bot_count,
                SUM(CASE WHEN direction = :dir_out AND sent_by_user_id IS NOT NULL THEN 1 ELSE 0 END) as agent_count
            FROM messages
            WHERE tenant_id = :tid AND created_at >= :start AND created_at < :end
            GROUP BY conversation_id',
            [
                'tid' => $tenantId,
                'start' => $window['start'],
                'end' => $window['end'],
                'dir_out' => 'outbound',
                'dir_in' => 'inbound',
            ],
        );

        if ($messageStats === []) {
            return [];
        }

        $conversationIds = array_map(
            fn ($row) => (string) $row->conversation_id,
            $messageStats,
        );

        $conversations = DB::select(
            'SELECT id, handoff_requested_at
            FROM conversations
            WHERE tenant_id = ? AND id IN ('.implode(',', array_fill(0, count($conversationIds), '?')).')',
            array_merge([$tenantId], $conversationIds),
        );

        $conversationMap = [];
        foreach ($conversations as $conv) {
            $conversationMap[(string) $conv->id] = $conv;
        }

        $results = [];
        foreach ($messageStats as $stat) {
            $convId = (string) $stat->conversation_id;
            $conv = $conversationMap[$convId] ?? null;

            $firstInbound = $stat->first_inbound_at !== null
                ? SupportCarbon::parse($stat->first_inbound_at)
                : null;
            $firstOutbound = $stat->first_outbound_at !== null
                ? SupportCarbon::parse($stat->first_outbound_at)
                : null;

            $responseTime = null;
            if ($firstInbound !== null && $firstOutbound !== null && $firstOutbound->gte($firstInbound)) {
                $responseTime = (int) $firstInbound->diffInSeconds($firstOutbound);
            }

            $handoffAt = $conv?->handoff_requested_at;

            $results[] = [
                'conversation_id' => $convId,
                'first_response_at' => $stat->first_outbound_at,
                'last_message_at' => $stat->last_message_at,
                'resolved_at' => null,
                'response_time_seconds' => $responseTime,
                'handle_time_seconds' => null,
                'message_count' => (int) $stat->message_count,
                'bot_message_count' => (int) $stat->bot_count,
                'agent_message_count' => (int) $stat->agent_count,
                'handoff_requested' => $handoffAt !== null,
                'handoff_at' => $handoffAt,
            ];
        }

        return $results;
    }

    /**
     * @param  list<array{response_time_seconds: ?int}>  $conversationMetricsData
     */
    private function computeAvgResponseTime(string $tenantId, array $conversationMetricsData): ?int
    {
        $values = array_filter(
            $conversationMetricsData,
            fn (array $cm) => $cm['response_time_seconds'] !== null && $cm['response_time_seconds'] >= 0,
        );

        if ($values === []) {
            return null;
        }

        $sum = array_sum(array_column($values, 'response_time_seconds'));

        return (int) round($sum / count($values));
    }
}
