<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Analytics\Models\AnalyticsDailyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily analytics aggregate (FASE 21 U1, ADR-077).
 *
 * Pre-computed daily rollup per tenant. One row per (tenant_id, date).
 * Populated by AggregateDailyAnalyticsJob (U2, not yet implemented).
 *
 * Privacy: stores ONLY aggregated counters, durations, and booleans.
 * NO PII: no phone, email, name, message body, AI prompt/response, API keys.
 *
 * No soft deletes — append-only aggregation table.
 * UNIQUE(tenant_id, date) enforced at DB level.
 */
final class AnalyticsDaily extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AnalyticsDailyFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'analytics_daily';

    /** @var list<string> */
    protected $fillable = [
        'date',
        'total_messages',
        'messages_inbound',
        'messages_outbound',
        'messages_delivered',
        'messages_read',
        'messages_failed',
        'total_conversations',
        'conversations_open',
        'conversations_resolved',
        'conversations_archived',
        'conversations_handoff_requested',
        'conversations_bot_paused',
        'unique_contacts',
        'avg_response_time_seconds',
        'total_flow_executions',
        'flow_executions_completed',
        'flow_executions_failed',
        'total_leads',
        'leads_new',
        'leads_won',
        'leads_lost',
        'total_ai_tokens',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_messages' => 'integer',
            'messages_inbound' => 'integer',
            'messages_outbound' => 'integer',
            'messages_delivered' => 'integer',
            'messages_read' => 'integer',
            'messages_failed' => 'integer',
            'total_conversations' => 'integer',
            'conversations_open' => 'integer',
            'conversations_resolved' => 'integer',
            'conversations_archived' => 'integer',
            'conversations_handoff_requested' => 'integer',
            'conversations_bot_paused' => 'integer',
            'unique_contacts' => 'integer',
            'avg_response_time_seconds' => 'integer',
            'total_flow_executions' => 'integer',
            'flow_executions_completed' => 'integer',
            'flow_executions_failed' => 'integer',
            'total_leads' => 'integer',
            'leads_new' => 'integer',
            'leads_won' => 'integer',
            'leads_lost' => 'integer',
            'total_ai_tokens' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
