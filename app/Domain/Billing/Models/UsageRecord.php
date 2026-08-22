<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Billing\Models\UsageRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only usage ledger (FASE 23 U1, ADR-009, ADR-088).
 *
 * NEVER updated or deleted. Immutable ledger for billing accuracy.
 * UPSERT contract: UNIQUE(tenant_id, subscription_id, category, period_start).
 */
final class UsageRecord extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<UsageRecordFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'usage_records';

    /** @var list<string> */
    protected $fillable = [
        'subscription_id',
        'category',
        'quantity',
        'description',
        'metadata',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => UsageCategory::class,
            'quantity' => 'integer',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
