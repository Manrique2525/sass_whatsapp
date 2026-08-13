<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Número de WhatsApp conectado por un tenant (FASE 6).
 *
 * `phone_id` es el `phone_number_id` de Meta y la clave de resolución de tenant
 * en el webhook. El webhook lo consulta SIN contexto (`withoutTenantScope()`);
 * cualquier otro acceso queda scopeado por `BelongsToTenant`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $whatsapp_account_id
 * @property string $phone_id
 * @property string|null $display_phone_number
 * @property string|null $verified_name
 * @property string|null $quality_rating
 * @property PhoneNumberStatus $status
 * @property bool $is_default
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class WhatsAppPhoneNumber extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_phone_numbers';

    protected $fillable = [
        'whatsapp_account_id',
        'phone_id',
        'display_phone_number',
        'verified_name',
        'quality_rating',
        'status',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'status' => PhoneNumberStatus::class,
            'is_default' => 'boolean',
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
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    public function isConnected(): bool
    {
        return $this->status->isConnected();
    }
}
