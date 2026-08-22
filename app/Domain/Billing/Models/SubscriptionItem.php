<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Billing\Models\SubscriptionItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Individual quota line within a subscription (FASE 23 U1, ADR-088).
 *
 * Each item represents one usage category and its included quota.
 * Example: a Pro subscription has items for messages (50000),
 * ai_tokens (500000), contacts (10000), etc.
 *
 * tenant_id is denormalized from subscription for query scoping.
 * Composite FK strategy: subscription_id → subscriptions + application-level
 * validation ensures tenant_id matches subscription.tenant_id.
 */
final class SubscriptionItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SubscriptionItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'subscription_items';

    /** @var list<string> */
    protected $fillable = [
        'subscription_id',
        'category',
        'included_usage',
    ];

    protected function casts(): array
    {
        return [
            'category' => UsageCategory::class,
            'included_usage' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasOneThrough<Plan, Subscription, $this>
     */
    public function plan(): HasOneThrough
    {
        return $this->hasOneThrough(
            Plan::class,
            Subscription::class,
            'id',
            'id',
            'subscription_id',
            'plan_id',
        );
    }
}
