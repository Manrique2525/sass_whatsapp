<?php

declare(strict_types=1);

namespace Database\Factories\Domain\KnowledgeBase\Models;

use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeDocument>
 */
final class KnowledgeDocumentFactory extends Factory
{
    protected $model = KnowledgeDocument::class;

    public function definition(): array
    {
        $filename = fake()->unique()->words(2, true).'.'.fake()->randomElement(['pdf', 'txt', 'md']);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'knowledge_base_id' => (string) Str::uuid(),
            'original_filename' => $filename,
            'storage_disk' => 'minio',
            'storage_path' => 'tenant/'.Str::uuid().'/knowledge-bases/'.Str::uuid().'/'.Str::uuid().'/'.$filename,
            'mime_type' => match (pathinfo($filename, PATHINFO_EXTENSION)) {
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'md' => 'text/markdown',
                default => 'text/plain',
            },
            'file_size' => fake()->numberBetween(100, 10_000_000),
            'file_hash' => bin2hex(random_bytes(32)),
            'status' => KnowledgeDocumentStatus::Uploaded,
            'error_message' => null,
            'chunk_count' => null,
            'total_tokens' => null,
            'processed_at' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => KnowledgeDocumentStatus::Ready,
            'chunk_count' => fake()->numberBetween(1, 50),
            'total_tokens' => fake()->numberBetween(100, 5000),
            'processed_at' => fake()->dateTime(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => KnowledgeDocumentStatus::Processing,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => KnowledgeDocumentStatus::Failed,
            'error_message' => 'Extraction failed: unsupported format',
        ]);
    }
}
