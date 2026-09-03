<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\WhatsApp\Enums\WhatsAppTemplateStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Template de WhatsApp del catálogo de un tenant/account (FASE 31 U5, ADR-121).
 *
 * La fuente de verdad del estado es el catálogo de Meta (vía sync); el SaaS lee,
 * sincroniza y ENVÍA templates aprobados. `components` guarda schema normalizado
 * (HEADER/BODY/FOOTER/BUTTONS), nunca estructura ejecutable.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $whatsapp_account_id
 * @property string|null $provider_template_id
 * @property string $name
 * @property string $language
 * @property string|null $category
 * @property WhatsAppTemplateStatus $status
 * @property array<int, array<string, mixed>>|null $components
 * @property Carbon|null $last_synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class WhatsAppTemplate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'whatsapp_account_id',
        'provider_template_id',
        'name',
        'language',
        'category',
        'status',
        'components',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppTemplateStatus::class,
            'components' => 'array',
            'last_synced_at' => 'datetime',
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
     * @return BelongsTo<WhatsAppAccount, $this>
     */
    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function canSend(): bool
    {
        return $this->status->canSend();
    }
}
