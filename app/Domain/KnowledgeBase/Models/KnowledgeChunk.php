<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\KnowledgeBase\Models\KnowledgeChunkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chunk de texto + embedding vectorial (FASE 17, ADR-058).
 *
 * Cada chunk almacena un fragmento de texto extraído de un documento y su
 * embedding para búsqueda semántica con pgvector.
 *
 * Dimensión fija: vector(1536) — contrato con text-embedding-3-small.
 *
 * Sin soft delete: chunks son datos derivados regenerables. Al eliminar
 * el documento padre, CASCADE elimina todos sus chunks.
 *
 * `metadata` JSONB almacena provenance opcional (page, section, headers).
 */
final class KnowledgeChunk extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<KnowledgeChunkFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'knowledge_chunks';

    protected function casts(): array
    {
        return [
            'token_count' => 'integer',
            'chunk_index' => 'integer',
            'metadata' => 'array',
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
