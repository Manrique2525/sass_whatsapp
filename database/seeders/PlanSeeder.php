<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the minimum required plan for U1 data foundation.
 *
 * Per ADR-088: no plan definitions exist in docs/code yet.
 * This seeder provides the single `free` plan that ensures
 * tenants.plan_id FK has a valid default target.
 *
 * FASE 24 (Billing/Stripe) will extend with full plan catalog.
 * This seeder is IDEMPOTENT — safe to run repeatedly.
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => 'Free tier with basic limits',
                'is_active' => true,
                'price_monthly' => 0,
                'price_yearly' => 0,
                'limits' => [
                    'messages' => 100,
                    'ai_tokens' => 1000,
                    'contacts' => 50,
                    'flow_executions' => 10,
                    'users' => 3,
                    'knowledge_documents' => 2,
                ],
                'features' => [
                    'ai_enabled' => false,
                ],
                'sort_order' => 0,
            ],
        );
    }
}
