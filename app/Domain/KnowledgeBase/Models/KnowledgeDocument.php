<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Models;

use App\Domain\KnowledgeBase\Enums\KnowledgeDocumentStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\KnowledgeBase\Models\KnowledgeDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Documento subido a una knowledge base (FASE 17, ADR-058).
 *
 * Registra metadata del archivo (ubicación S3, tamaño, hash, estado).
 * El binario se almacena en S3/MinIO; esta tabla nunca contiene datos
 * binarios.
 *
 * FK compuesta (tenant_id, knowledge_base_id) garantiza a nivel DB que un
 * documento no pueda apuntar a una KB de otro tenant.
 *
 * Soft delete para conservar metadata de auditoría.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $knowledge_base_id
 * @property string $original_filename
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property string|null $file_hash
 * @property KnowledgeDocumentStatus $status
 * @property string|null $error_message
 * @property int $chunk_count
 * @property int|null $total_tokens
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class KnowledgeDocument extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<KnowledgeDocumentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'knowledge_documents';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'knowledge_base_id',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
        'file_hash',
        'status',
        'error_message',
        'chunk_count',
        'total_tokens',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'chunk_count' => 'integer',
            'total_tokens' => 'integer',
            'processed_at' => 'datetime',
            'status' => KnowledgeDocumentStatus::class,
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
     * @return BelongsTo<KnowledgeBase, $this>
     */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    /**
     * @return HasMany<KnowledgeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }
}
