<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

final class LandingController extends Controller
{
    public function __invoke(): Response
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (Plan $plan): array => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'description' => $plan->description,
                'priceMonthly' => (string) $plan->price_monthly,
                'priceYearly' => (string) $plan->price_yearly,
                'limits' => [
                    'messages' => $plan->getLimit('messages'),
                    'contacts' => $plan->getLimit('contacts'),
                    'flowExecutions' => $plan->getLimit('flow_executions'),
                    'users' => $plan->getLimit('users'),
                    'knowledgeDocuments' => $plan->getLimit('knowledge_documents'),
                ],
                'aiIncluded' => $plan->hasFeature('ai_enabled'),
            ])
            ->values()
            ->all();

        return Inertia::render('Landing', [
            'plans' => $plans,
        ]);
    }
}
