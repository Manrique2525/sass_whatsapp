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
        $plan = Plan::query()
            ->where('slug', 'free')
            ->where('is_active', true)
            ->firstOrFail();

        return Inertia::render('Landing', [
            'freePlan' => [
                'name' => $plan->name,
                'slug' => $plan->slug,
                'limits' => [
                    'messages' => $plan->getLimit('messages'),
                    'contacts' => $plan->getLimit('contacts'),
                    'flowExecutions' => $plan->getLimit('flow_executions'),
                    'users' => $plan->getLimit('users'),
                    'knowledgeDocuments' => $plan->getLimit('knowledge_documents'),
                ],
                'aiIncluded' => $plan->hasFeature('ai_enabled'),
            ],
        ]);
    }
}
