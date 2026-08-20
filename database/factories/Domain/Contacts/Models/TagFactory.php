<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Contacts\Models;

use App\Domain\Contacts\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'name' => 'tag-'.Str::lower(Str::random(8)),
        ];
    }
}
