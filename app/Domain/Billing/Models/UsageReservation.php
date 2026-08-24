<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\UsageCategory;
use App\Domain\Billing\Enums\UsageReservationStatus;
use App\Domain\Tenants\Traits\BelongsToTenant;
use Database\Factories\Domain\Billing\Models\UsageReservationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $subscription_id
 * @property UsageCategory $category
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property int $quantity
 * @property string|null $idempotency_key
 * @property UsageReservationStatus $status
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon $reserved_at
 * @property \Illuminate\Support\Carbon|null $committed_at
 * @property \Illuminate\Support\Carbon|null $released_at
 */
final class UsageReservation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<UsageReservationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'usage_reservations';

    /** @var list<string> */
    protected $fillable = [
        'subscription_id',
        'category',
        'period_start',
        'period_end',
        'quantity',
        'idempotency_key',
        'status',
        'expires_at',
        'reserved_at',
        'committed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => UsageCategory::class,
            'status' => UsageReservationStatus::class,
            'quantity' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'expires_at' => 'datetime',
            'reserved_at' => 'datetime',
            'committed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            UsageReservationStatus::Reserved,
            UsageReservationStatus::Committed,
        ], true) && ! $this->isExpired();
    }
}
