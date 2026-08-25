<?php

declare(strict_types=1);

namespace App\Application\Billing\Guards;

use App\Domain\Billing\Contracts\CapacityCheckInterface;
use Closure;

final class CapacityCheck implements CapacityCheckInterface
{
    /**
     * @param  Closure(): void  $assertion
     */
    public function __construct(private readonly Closure $assertion) {}

    public function assertCanCreate(): void
    {
        ($this->assertion)();
    }
}
