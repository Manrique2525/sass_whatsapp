<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Billing\Models\SubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-scoped subscription (FASE 23 U1, ADR-088).
 *
 * Links a tenant to their current plan. One active subscription per tenant
 * enforced by UNIQUE partial index: UNIQUE(tenant_id) WHERE deleted_at IS NULL.
 *
 * Source of truth for plan assignment. tenants.plan_id is a denormalized
 * cache kept in sync by SubscriptionService.
 *
 * Soft deletes preserve subscription history for audit while allowing
 * the unique constraint to permit plan changes (cancelled old + new active).
 */
final class Subscription extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'plan_id',
        'status',
        'quantity',
        'current_period_start',
        'current_period_end',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'quantity' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'metadata' => 'array',
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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    /**
     * @return HasMany<UsageRecord, $this>
     */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    public function isActive(): bool
    {
        /** @var SubscriptionStatus $status */
        $status = $this->status;

        return $status === SubscriptionStatus::Active;
    }
}
