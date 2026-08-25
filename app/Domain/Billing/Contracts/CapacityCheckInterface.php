<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

interface CapacityCheckInterface
{
    public function assertCanCreate(): void;
}
