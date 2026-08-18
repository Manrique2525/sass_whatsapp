<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\KnowledgeBase\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Documento de Knowledge Base (FASE 17 U2.1).
 *
 * NO expone: storage_disk, storage_path, file_hash, error_message interno,
 * embedding, chunks content. Solo campos seguros para el API consumer.
 *
 * @mixin KnowledgeDocument
 */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'status' => $this->status->value,
            'chunk_count' => $this->chunk_count,
            'total_tokens' => $this->total_tokens,
            'processed_at' => $this->processed_at?->toISOString(),
            'error_message' => $this->when(
                $this->resource->relationLoaded('knowledgeBase') && $this->status->value === 'failed',
                fn (): ?string => $this->error_message,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
