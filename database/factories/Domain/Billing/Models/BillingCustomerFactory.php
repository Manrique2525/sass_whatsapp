<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Billing\Models;

use App\Domain\Billing\Models\BillingCustomer;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingCustomer>
 */
class BillingCustomerFactory extends Factory
{
    protected $model = BillingCustomer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_'.fake()->bothify('????????????????'),
        ];
    }
}
