<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Application\Platform\Services\PlatformPlanService;
use App\Domain\Billing\Models\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformPlanRequest;
use App\Http\Requests\Platform\UpdatePlatformPlanRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformPlanController extends Controller
{
    public function __construct(private readonly PlatformPlanService $plans) {}

    public function index(): Response
    {
        return Inertia::render('Platform/Plans/Index', ['plans' => $this->plans->summaries()]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Plans/Create');
    }

    public function store(StorePlatformPlanRequest $request): RedirectResponse
    {
        $plan = $this->plans->create($request->validated());

        return to_route('platform.plans.show', $plan);
    }

    public function show(Plan $plan): Response
    {
        return Inertia::render('Platform/Plans/Show', ['plan' => $this->plans->present($plan)]);
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('Platform/Plans/Edit', ['plan' => $this->plans->present($plan)]);
    }

    public function update(UpdatePlatformPlanRequest $request, Plan $plan): RedirectResponse
    {
        $this->plans->update($plan, $request->validated());

        return to_route('platform.plans.show', $plan);
    }
}
