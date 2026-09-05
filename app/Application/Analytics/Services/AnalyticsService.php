<?php

declare(strict_types=1);

namespace App\Application\Analytics\Services;

use App\Application\Users\Services\AuthorizationService;
use App\Domain\Analytics\Models\AnalyticsDaily;
use App\Domain\Analytics\ValueObjects\AnalyticsOverview;
use App\Domain\Tenants\Exceptions\PermissionDeniedException;
use App\Domain\Tenants\Exceptions\TenantMembershipException;
use App\Domain\Tenants\Exceptions\TenantNotActiveException;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics service (FASE 21 U3, ADR-079).
 *
 * Reads pre-computed analytics_daily rows materialized by AggregationService (U2).
 * No raw message/flow/lead counting — source of truth is analytics_daily.
 *
 * Avg response time: computed from conversation_metrics for exact weighted average,
 * not AVG of daily averages (which would be incorrect for unequal day weights).
 *
 * Cache: tenant-scoped keys with 300s TTL via Cache::remember.
 * Eventual consistency ≤5 min after U2 aggregation runs.
 */
final class AnalyticsService
{
    private const DEFAULT_RANGE_DAYS = 30;

    private const CACHE_TTL = 300;

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @param  array{from?: string, to?: string}  $params
     *
     * @throws TenantMembershipException
     * @throws PermissionDeniedException
     * @throws TenantNotActiveException
     */
    public function getOverview(User $user, Tenant $tenant, array $params): AnalyticsOverview
    {
        $this->authorization->authorize($user, TenantPermission::ViewAnalytics, $tenant);

        $tenantTimezone = $tenant->timezone ?? config('app.timezone', 'UTC');

        try {
            new \DateTimeZone($tenantTimezone);
        } catch (\Exception) {
            $tenantTimezone = 'UTC';
        }

        $to = isset($params['to'])
            ? Carbon::parse($params['to'], $tenantTimezone)->startOfDay()
            : Carbon::today($tenantTimezone);

        $from = isset($params['from'])
            ? Carbon::parse($params['from'], $tenantTimezone)->startOfDay()
            : $to->copy()->subDays(self::DEFAULT_RANGE_DAYS - 1)->startOfDay();

        $tenantId = $tenant->id;
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $cacheKey = "tenant:{$tenantId}:analytics:overview:{$fromStr}:{$toStr}";

        /** @var AnalyticsOverview */
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $fromStr, $toStr): AnalyticsOverview {
            $previousTenantId = TenantContext::id();
            TenantContext::setId($tenantId);

            try {
                $dailyRows = AnalyticsDaily::query()
                    ->withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->where('date', '>=', $fromStr)
                    ->where('date', '<=', $toStr)
                    ->orderBy('date')
                    ->get();

                $period = ['from' => $fromStr, 'to' => $toStr];

                $messages = $this->aggregateMessages($dailyRows);
                $conversations = $this->aggregateConversations($dailyRows, $tenantId, $fromStr, $toStr);
                $flows = $this->aggregateFlows($dailyRows);
                $leads = $this->aggregateLeads($dailyRows);
                $ai = $this->aggregateAi($dailyRows);
                $daily = $dailyRows->isEmpty()
                    ? []
                    : $this->buildDailySeries($dailyRows, $fromStr, $toStr);

                return new AnalyticsOverview(
                    period: $period,
                    messages: $messages,
                    conversations: $conversations,
                    flows: $flows,
                    leads: $leads,
                    ai: $ai,
                    daily: $daily,
                );
            } finally {
                if ($previousTenantId !== null) {
                    TenantContext::setId($previousTenantId);
                }
            }
        });
    }

    /**
     * @param  Collection<int, AnalyticsDaily>  $rows
     * @return array{total: int, inbound: int, outbound: int, delivered: int, read: int, failed: int}
     */
    private function aggregateMessages(Collection $rows): array
    {
        $total = 0;
        $inbound = 0;
        $outbound = 0;
        $delivered = 0;
        $read = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $total += $row->total_messages;
            $inbound += $row->messages_inbound;
            $outbound += $row->messages_outbound;
            $delivered += $row->messages_delivered;
            $read += $row->messages_read;
            $failed += $row->messages_failed;
        }

        return [
            'total' => $total,
            'inbound' => $inbound,
            'outbound' => $outbound,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,
        ];
    }

    /**
     * @param  Collection<int, AnalyticsDaily>  $rows
     * @return array{total: int, open: int, resolved: int, archived: int, handoff_requested: int, bot_paused: int, unique_contacts: int, avg_response_time_seconds: ?int}
     */
    private function aggregateConversations(Collection $rows, string $tenantId, string $from, string $to): array
    {
        $total = 0;
        $open = 0;
        $resolved = 0;
        $archived = 0;
        $handoffRequested = 0;
        $botPaused = 0;
        $uniqueContacts = 0;

        foreach ($rows as $row) {
            $total += $row->total_conversations;
            $open += $row->conversations_open;
            $resolved += $row->conversations_resolved;
            $archived += $row->conversations_archived;
            $handoffRequested += $row->conversations_handoff_requested;
            $botPaused += $row->conversations_bot_paused;
            $uniqueContacts += $row->unique_contacts;
        }

        $avgResponseTime = $this->computeExactAvgResponseTime($tenantId, $from, $to);

        return [
            'total' => $total,
            'open' => $open,
            'resolved' => $resolved,
            'archived' => $archived,
            'handoff_requested' => $handoffRequested,
            'bot_paused' => $botPaused,
            'unique_contacts' => $uniqueContacts,
            'avg_response_time_seconds' => $avgResponseTime,
        ];
    }

    /**
     * @param  Collection<int, AnalyticsDaily>  $rows
     * @return array{total: int, completed: int, failed: int}
     */
    private function aggregateFlows(Collection $rows): array
    {
        $total = 0;
        $completed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $total += $row->total_flow_executions;
            $completed += $row->flow_executions_completed;
            $failed += $row->flow_executions_failed;
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
        ];
    }

    /**
     * @param  Collection<int, AnalyticsDaily>  $rows
     * @return array{total: int, new: int, won: int, lost: int}
     */
    private function aggregateLeads(Collection $rows): array
    {
        $total = 0;
        $new = 0;
        $won = 0;
        $lost = 0;

        foreach ($rows as $row) {
            $total += $row->total_leads;
            $new += $row->leads_new;
            $won += $row->leads_won;
            $lost += $row->leads_lost;
        }

        return [
            'total' => $total,
            'new' => $new,
            'won' => $won,
            'lost' => $lost,
        ];
    }

    /**
     * @param  Collection<int, AnalyticsDaily>  $rows
     * @return array{total_tokens: int}
     */
    private function aggregateAi(Collection $rows): array
    {
        $totalTokens = 0;

        foreach ($rows as $row) {
            $totalTokens += $row->total_ai_tokens;
        }

        return ['total_tokens' => $totalTokens];
    }

    /**
     * @param  Collection<int, AnalyticsDaily>  $rows
     * @return list<array{date: string, messages_total: int, messages_inbound: int, messages_outbound: int, conversations_total: int, leads_total: int, flow_executions_total: int, ai_tokens: int}>
     */
    private function buildDailySeries(Collection $rows, string $from, string $to): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $dateValue = $row->getAttribute('date');
            $dateStr = is_string($dateValue) ? $dateValue : $dateValue->toDateString();
            $byDate[$dateStr] = $row;
        }

        $series = [];
        $current = Carbon::parse($from);
        $end = Carbon::parse($to);

        while ($current->lte($end)) {
            $dateStr = $current->toDateString();
            $row = $byDate[$dateStr] ?? null;

            $series[] = [
                'date' => $dateStr,
                'messages_total' => $row !== null ? $row->total_messages : 0,
                'messages_inbound' => $row !== null ? $row->messages_inbound : 0,
                'messages_outbound' => $row !== null ? $row->messages_outbound : 0,
                'conversations_total' => $row !== null ? $row->total_conversations : 0,
                'leads_total' => $row !== null ? $row->total_leads : 0,
                'flow_executions_total' => $row !== null ? $row->total_flow_executions : 0,
                'ai_tokens' => $row !== null ? $row->total_ai_tokens : 0,
            ];

            $current->addDay();
        }

        return $series;
    }

    private function computeExactAvgResponseTime(string $tenantId, string $from, string $to): ?int
    {
        $exclusiveTo = Carbon::parse($to)->addDay()->startOfDay()->toDateTimeString();

        $row = DB::selectOne(
            'SELECT AVG(response_time_seconds) as avg_rt
            FROM conversation_metrics
            WHERE tenant_id = ?
              AND response_time_seconds IS NOT NULL
              AND response_time_seconds >= 0
              AND first_response_at >= ?
              AND first_response_at < ?',
            [$tenantId, $from, $exclusiveTo],
        );

        if ($row === null || $row->avg_rt === null) {
            return null;
        }

        $avg = (float) $row->avg_rt;

        if (! is_finite($avg) || $avg < 0) {
            return null;
        }

        return (int) round($avg);
    }
}
