<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Billing\Models;

use App\Domain\Billing\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'is_active' => true,
            'price_monthly' => 0,
            'price_yearly' => 0,
            'limits' => [
                'messages' => 100,
                'ai_tokens' => 1000,
                'contacts' => 50,
                'flow_executions' => 10,
                'users' => 1,
                'knowledge_documents' => 2,
            ],
            'features' => [
                'ai_enabled' => false,
            ],
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
