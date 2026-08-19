<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Faq\Models;

use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Models\Faq;
use App\Domain\Faq\ValueObjects\FaqQuestionNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Faq>
 */
final class FaqFactory extends Factory
{
    protected $model = Faq::class;

    public function definition(): array
    {
        $normalizer = new FaqQuestionNormalizer;
        $question = fake()->unique()->sentence(random_int(3, 6));

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'question' => $question,
            'normalized_question' => $normalizer->normalize($question),
            'answer' => fake()->paragraph(1),
            'status' => FaqStatus::Active,
            'priority' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => FaqStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => FaqStatus::Inactive]);
    }
}
