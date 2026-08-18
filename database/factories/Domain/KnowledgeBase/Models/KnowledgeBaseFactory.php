<?php

declare(strict_types=1);

namespace Database\Factories\Domain\KnowledgeBase\Models;

use App\Domain\KnowledgeBase\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeBase>
 */
final class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional(0.7)->sentence(),
        ];
    }
}
