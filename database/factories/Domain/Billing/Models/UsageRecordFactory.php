<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Billing\Models;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageRecord;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageRecord>
 */
class UsageRecordFactory extends Factory
{
    protected $model = UsageRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'category' => UsageCategory::Messages,
            'quantity' => fake()->numberBetween(1, 10),
            'description' => null,
            'metadata' => [],
            'recorded_at' => now(),
        ];
    }
}
