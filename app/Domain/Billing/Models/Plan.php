<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use Database\Factories\Domain\Billing\Models\PlanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global plan catalog (FASE 23 U1, ADR-088).
 *
 * Plans are platform-global — NOT tenant-scoped. Every tenant sees the
 * same catalog. Managed by super_admin only.
 *
 * limits JSON: per-category quotas (messages, ai_tokens, contacts,
 * flow_executions, users, knowledge_documents).
 * features JSON: feature flags (ai_enabled, webhooks, etc.).
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'plans';

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_active',
        'price_monthly',
        'price_yearly',
        'limits',
        'features',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'limits' => 'array',
            'features' => 'array',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get a specific limit value for this plan.
     *
     * Returns null if the category is not defined in limits.
     * Returns PHP_INT_MAX for "unlimited" plans.
     */
    public function getLimit(string $category): ?int
    {
        /** @var array<string, int> $limits */
        $limits = $this->limits ?? [];

        return $limits[$category] ?? null;
    }

    /**
     * Check if a feature is enabled for this plan.
     */
    public function hasFeature(string $feature): bool
    {
        /** @var array<string, bool> $features */
        $features = $this->features ?? [];

        return $features[$feature] ?? false;
    }
}
