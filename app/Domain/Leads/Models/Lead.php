<?php

declare(strict_types=1);

namespace App\Domain\Leads\Models;

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Leads\Models\LeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lead de CRM básico (FASE 19, ADR-072).
 *
 * Entidad tenant-scoped que representa una oportunidad de negocio
 * capturada manualmente. Independiente de Contact (sin FK a contacts).
 *
 * phone/email se almacenan normalizados (LeadPhoneNormalizer/LeadEmailNormalizer).
 * En U1 la normalización ocurre a nivel de dominio; en U2 el service layer
 * orquestará la normalización antes de persistir.
 */
final class Lead extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'leads';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'status',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
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
