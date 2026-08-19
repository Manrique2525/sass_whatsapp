<?php

declare(strict_types=1);

namespace App\Domain\Faq\Models;

use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Faq\Models\FaqFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pregunta frecuente curada por el tenant (FASE 18, ADR-069).
 *
 * FAQ es una entidad de dominio independiente: respuesta textual
 * determinista, sin embeddings, sin fuzzy matching, sin AI.
 *
 * normalized_question se genera vía FaqQuestionNormalizer al crear/actualizar.
 * El matcher futuro (U2) usará exactamente la misma función de normalización.
 */
final class Faq extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FaqFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'faqs';

    /** @var list<string> */
    protected $fillable = [
        'question',
        'normalized_question',
        'answer',
        'status',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'status' => FaqStatus::class,
            'priority' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
