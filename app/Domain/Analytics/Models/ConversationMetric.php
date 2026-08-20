<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Analytics\Models\ConversationMetricFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-conversation analytics metrics (FASE 21 U1, ADR-077).
 *
 * One row per conversation. Populated when conversation reaches a terminal
 * state or periodically by aggregation job (U2).
 *
 * Privacy: stores ONLY durations, counts, and booleans.
 * NO PII: no message body, phone, email, AI prompts/responses.
 *
 * No soft deletes — historical record of conversation performance.
 * UNIQUE(tenant_id, conversation_id) enforced at DB level.
 */
final class ConversationMetric extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ConversationMetricFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'conversation_metrics';

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'first_response_at',
        'last_message_at',
        'resolved_at',
        'response_time_seconds',
        'handle_time_seconds',
        'message_count',
        'bot_message_count',
        'agent_message_count',
        'handoff_requested',
        'handoff_at',
    ];

    protected function casts(): array
    {
        return [
            'first_response_at' => 'datetime',
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
            'response_time_seconds' => 'integer',
            'handle_time_seconds' => 'integer',
            'message_count' => 'integer',
            'bot_message_count' => 'integer',
            'agent_message_count' => 'integer',
            'handoff_requested' => 'boolean',
            'handoff_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
