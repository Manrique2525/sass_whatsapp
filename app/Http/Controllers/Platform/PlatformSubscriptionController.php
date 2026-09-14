<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Application\Platform\Services\PlatformSubscriptionService;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformChangePlanRequest;
use App\Http\Requests\Platform\PlatformPlanPreviewRequest;
use App\Http\Requests\Platform\PlatformSubscriptionIndexRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformSubscriptionController extends Controller
{
    public function __construct(private readonly PlatformSubscriptionService $subscriptions) {}

    public function index(PlatformSubscriptionIndexRequest $request): Response
    {
        $paginator = $this->subscriptions->paginate($request->validated());

        return Inertia::render('Platform/Subscriptions/Index', [
            'subscriptions' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $request->validated(),
            'plans' => $this->subscriptions->activePlans(),
        ]);
    }

    public function show(string $subscription): Response
    {
        try {
            $data = $this->subscriptions->show($subscription);
        } catch (SubscriptionNotFoundException) {
            abort(404);
        }

        return Inertia::render('Platform/Subscriptions/Show', $data);
    }

    public function edit(Tenant $tenant): Response
    {
        return Inertia::render('Platform/Subscriptions/ChangePlan', $this->subscriptions->changeForm($tenant));
    }

    public function preview(PlatformPlanPreviewRequest $request, Tenant $tenant): JsonResponse
    {
        return response()->json($this->subscriptions->preview($tenant, $request->validated('plan_id')));
    }

    public function updatePlan(PlatformChangePlanRequest $request, Tenant $tenant): RedirectResponse
    {
        $this->subscriptions->changePlan(
            $request->user(),
            $tenant,
            $request->validated('plan_id'),
            $request->validated('reason'),
        );

        return to_route('platform.customers.show', $tenant)
            ->with('success', 'Subscription plan changed and recorded in the platform audit.');
    }
}
