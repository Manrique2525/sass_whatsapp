<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Leads\Models;

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'name' => fake()->name(),
            'phone' => '+'.fake()->numerify('##############'),
            'email' => fake()->unique()->safeEmail(),
            'status' => LeadStatus::New,
            'source' => fake()->randomElement(['manual', 'whatsapp', 'web', 'referral', 'other']),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    public function asNew(): static
    {
        return $this->state(fn () => ['status' => LeadStatus::New]);
    }

    public function contacted(): static
    {
        return $this->state(fn () => ['status' => LeadStatus::Contacted]);
    }

    public function qualified(): static
    {
        return $this->state(fn () => ['status' => LeadStatus::Qualified]);
    }

    public function won(): static
    {
        return $this->state(fn () => ['status' => LeadStatus::Won]);
    }

    public function lost(): static
    {
        return $this->state(fn () => ['status' => LeadStatus::Lost]);
    }

    public function withoutPhone(): static
    {
        return $this->state(fn () => ['phone' => null]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null]);
    }

    public function withoutSource(): static
    {
        return $this->state(fn () => ['source' => null]);
    }
}
