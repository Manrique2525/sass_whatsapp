<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Billing\Models;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Models\UsageReservation;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<UsageReservation>
 */
class UsageReservationFactory extends Factory
{
    protected $model = UsageReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = Carbon::parse('2026-08-01')->startOfMinute();
        $periodEnd = Carbon::parse('2026-09-01')->startOfMinute();

        return [
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'category' => UsageCategory::Messages,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'quantity' => 1,
            'idempotency_key' => null,
            'status' => UsageReservationStatus::Reserved,
            'expires_at' => now()->addSeconds(300),
            'reserved_at' => now(),
            'committed_at' => null,
            'released_at' => null,
        ];
    }

    public function committed(): static
    {
        return $this->state(fn (): array => [
            'status' => UsageReservationStatus::Committed,
            'committed_at' => now(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => UsageReservationStatus::Released,
            'released_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
