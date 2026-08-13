<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Traza de auditoría (plataforma, sin scope tenant).
 *
 * @property array<string, mixed>|null $data
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'actor_user_id',
        'tenant_id',
        'action',
        'subject_type',
        'subject_id',
        'data',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
