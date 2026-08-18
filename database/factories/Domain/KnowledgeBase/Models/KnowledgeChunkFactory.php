<?php

declare(strict_types=1);

namespace Database\Factories\Domain\KnowledgeBase\Models;

use App\Domain\KnowledgeBase\Models\KnowledgeChunk;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeChunk>
 */
final class KnowledgeChunkFactory extends Factory
{
    protected $model = KnowledgeChunk::class;

    public function definition(): array
    {
        $definition = [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'document_id' => (string) Str::uuid(),
            'content' => fake()->paragraph(3),
            'token_count' => fake()->numberBetween(10, 500),
            'chunk_index' => fake()->numberBetween(0, 10),
            'metadata' => null,
        ];

        if ($this->hasEmbeddingColumn()) {
            $definition['embedding'] = $this->randomEmbedding(1536);
        }

        return $definition;
    }

    public function withEmbedding(int $dimensions = 1536): static
    {
        return $this->state(fn () => [
            'embedding' => $this->randomEmbedding($dimensions),
        ]);
    }

    public function withVector(array $vector): static
    {
        return $this->state(fn () => [
            'embedding' => $vector,
        ]);
    }

    private function hasEmbeddingColumn(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'pgsql'
                && Schema::hasColumn('knowledge_chunks', 'embedding');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<float>
     */
    private function randomEmbedding(int $dimensions): array
    {
        $vector = [];
        for ($i = 0; $i < $dimensions; $i++) {
            $vector[] = mt_rand(-1000, 1000) / 1000;
        }

        return $vector;
    }
}
