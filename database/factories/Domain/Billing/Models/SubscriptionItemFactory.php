<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Billing\Models;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\SubscriptionItem;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionItem>
 */
class SubscriptionItemFactory extends Factory
{
    protected $model = SubscriptionItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'tenant_id' => Tenant::factory(),
            'category' => UsageCategory::Messages,
            'included_usage' => 100,
        ];
    }
}
