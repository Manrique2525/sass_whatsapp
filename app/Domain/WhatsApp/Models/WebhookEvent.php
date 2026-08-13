<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\WhatsApp\Enums\WebhookEventStatus;
use App\Domain\WhatsApp\Enums\WebhookEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Evento crudo recibido de Meta (FASE 6, outbox).
 *
 * Tabla de PLATAFORMA: NO lleva `BelongsToTenant` ni scope. Un evento de Meta
 * es único a nivel global (`provider_event_id` UNIQUE) y puede llegar antes de
 * resolver el tenant (`tenant_id` nullable). El dedupe real es la violación de
 * UNIQUE con `INSERT ... ON CONFLICT DO NOTHING`.
 *
 * @property string $id
 * @property string $provider_event_id
 * @property string|null $tenant_id
 * @property array<string, mixed>|null $payload
 * @property WebhookEventStatus $status
 * @property WebhookEventType|null $event_type
 * @property bool $duplicate
 * @property string|null $error_code
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider_event_id',
        'tenant_id',
        'payload',
        'status',
        'event_type',
        'duplicate',
        'error_code',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookEventStatus::class,
            'event_type' => WebhookEventType::class,
            'duplicate' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function markProcessed(): void
    {
        $this->status = WebhookEventStatus::Processed;
        $this->processed_at = now();
        $this->save();
    }

    public function markFailed(string $reason): void
    {
        $this->status = WebhookEventStatus::Failed;
        $this->error_code = $reason;
        $this->processed_at = now();
        $this->save();
    }
}
