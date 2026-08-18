<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeBase\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\KnowledgeBase\Models\KnowledgeBaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Knowledge base del tenant (FASE 17, ADR-058).
 *
 * Agrupa documentos para alimentar el contexto RAG del nodo AI.
 * Un tenant puede crear varias KBs; cada nodo AI apunta a una.
 *
 * Soft delete para conservar referencias en ejecuciones históricas.
 */
final class KnowledgeBase extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<KnowledgeBaseFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'knowledge_bases';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
    ];

    protected function casts(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<KnowledgeDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }
}
