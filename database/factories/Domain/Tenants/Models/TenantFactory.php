<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Tenants\Models;

use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $words = fake()->unique()->words(2, true);

        return [
            'name' => Str::headline($words),
            'slug' => Str::slug($words),
            'status' => TenantStatus::Active,
            'plan_id' => null,
            'timezone' => 'UTC',
            'locale' => 'en',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Suspended]);
    }
}
