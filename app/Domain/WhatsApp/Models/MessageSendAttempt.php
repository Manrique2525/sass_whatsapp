<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use App\Domain\WhatsApp\Enums\MessageSendStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Intento de envío de un mensaje por WhatsApp (FASE 6).
 *
 * Una fila por llamada al provider. `attempt`/`max_attempts` registran el
 * intento y el tope; el job de cola con backoff llega en la fase de mensajería.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $whatsapp_phone_number_id
 * @property string|null $provider_message_id
 * @property string $to
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property MessageSendStatus $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $attempt
 * @property int $max_attempts
 * @property Carbon|null $attempted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class MessageSendAttempt extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'whatsapp_phone_number_id',
        'provider_message_id',
        'to',
        'type',
        'payload',
        'status',
        'error_code',
        'error_message',
        'attempt',
        'max_attempts',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => MessageSendStatus::class,
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'attempted_at' => 'datetime',
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
     * @return BelongsTo<WhatsAppPhoneNumber, $this>
     */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(WhatsAppPhoneNumber::class);
    }
}
