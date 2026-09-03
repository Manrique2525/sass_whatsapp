<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\WhatsApp\Enums\WhatsAppAccountStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cuenta de WhatsApp Business (WABA) conectada por un tenant (FASE 6).
 *
 * `access_token` se guarda CIFRADO (cast `encrypted`, APP_KEY) y está en
 * `$hidden`: nunca se serializa en respuestas API.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $whatsapp_business_account_id
 * @property string|null $display_name
 * @property string|null $access_token
 * @property WhatsAppAccountStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class WhatsAppAccount extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'whatsapp_business_account_id',
        'display_name',
        'access_token',
        'status',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'status' => WhatsAppAccountStatus::class,
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
     * @return HasMany<WhatsAppPhoneNumber, $this>
     */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(WhatsAppPhoneNumber::class, 'whatsapp_account_id');
    }

    /**
     * @return HasMany<WhatsAppTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class, 'whatsapp_account_id');
    }

    public function isConnected(): bool
    {
        return $this->status->isConnected();
    }

    /**
     * Número conectado a usar para envíos (el marcado como default si existe).
     */
    public function connectedPhoneNumber(): ?WhatsAppPhoneNumber
    {
        return $this->phoneNumbers()
            ->where('status', 'connected')
            ->orderByDesc('is_default')
            ->first();
    }
}
