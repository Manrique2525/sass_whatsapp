<?php

declare(strict_types=1);

namespace App\Domain\Messages\Models;

use App\Domain\Messages\Enums\MessageMediaProcessingStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Asset de media de un mensaje (FASE 31 U5, ADR-121).
 *
 * El almacenamiento interno (`storage_disk`/`storage_path`) es OPACO y se
 * genera por la app; nunca se deriva de nombres de archivo del usuario ni se
 * expone por API. `sha256`/`mime`/`size` son valores VALIDADOS del contenido.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $message_id
 * @property string|null $provider_media_id
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $sha256
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $original_filename
 * @property MessageMediaProcessingStatus $processing_status
 * @property string|null $failure_reason
 * @property Carbon|null $downloaded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class MessageMedia extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'message_media';

    protected $fillable = [
        'message_id',
        'provider_media_id',
        'storage_disk',
        'storage_path',
        'sha256',
        'mime',
        'size',
        'original_filename',
        'processing_status',
        'failure_reason',
        'downloaded_at',
    ];

    /** Atributos internos jamás serializados a respuestas API. */
    protected $hidden = [
        'storage_disk',
        'storage_path',
        'provider_media_id',
    ];

    protected function casts(): array
    {
        return [
            'processing_status' => MessageMediaProcessingStatus::class,
            'size' => 'integer',
            'downloaded_at' => 'datetime',
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
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
