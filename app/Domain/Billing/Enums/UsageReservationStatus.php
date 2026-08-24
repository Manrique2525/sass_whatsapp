<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

enum UsageReservationStatus: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';
}
