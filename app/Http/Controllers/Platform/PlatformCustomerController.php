<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Application\Platform\Services\PlatformCustomerOperations;
use App\Application\Platform\Services\PlatformCustomerQueryService;
use App\Application\Platform\Services\ProvisionTenantCustomer;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformCustomerActionRequest;
use App\Http\Requests\Platform\PlatformCustomerIndexRequest;
use App\Http\Requests\Platform\PlatformTenantStatusRequest;
use App\Http\Requests\Platform\StorePlatformCustomerRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformCustomerController extends Controller
{
    public function __construct(
        private readonly PlatformCustomerQueryService $customers,
        private readonly ProvisionTenantCustomer $provisioner,
        private readonly PlatformCustomerOperations $operations,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Platform/Customers/Create');
    }

    public function store(StorePlatformCustomerRequest $request): RedirectResponse
    {
        $result = $this->provisioner->provision(
            $request->user(),
            $request->validated('tenant_name'),
            $request->validated('owner_name'),
            $request->validated('owner_email'),
            (bool) $request->validated('confirm_existing_owner', false),
        );

        return to_route('platform.customers.show', $result['tenant'])
            ->with('success', 'Free customer and Owner account created.');
    }

    public function index(PlatformCustomerIndexRequest $request): Response
    {
        $paginator = $this->customers->paginate($request->validated());

        return Inertia::render('Platform/Customers/Index', [
            'customers' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $request->validated(),
            'plans' => $this->customers->plans(),
        ]);
    }

    public function show(Tenant $tenant): Response
    {
        $customer = $this->customers->show($tenant->id);

        abort_if($customer === null, 404);

        return Inertia::render('Platform/Customers/Show', [
            'customer' => $customer,
        ]);
    }

    public function suspend(PlatformTenantStatusRequest $request, Tenant $tenant): RedirectResponse
    {
        $this->operations->changeStatus($request->user(), $tenant, TenantStatus::Suspended, $request->validated('reason'));

        return to_route('platform.customers.show', $tenant)->with('success', 'Tenant suspended.');
    }

    public function reactivate(PlatformTenantStatusRequest $request, Tenant $tenant): RedirectResponse
    {
        $this->operations->changeStatus($request->user(), $tenant, TenantStatus::Active, $request->validated('reason'));

        return to_route('platform.customers.show', $tenant)->with('success', 'Tenant reactivated.');
    }

    public function resendVerification(PlatformCustomerActionRequest $request, Tenant $tenant): RedirectResponse
    {
        $this->operations->resendOwnerVerification($request->user(), $tenant);

        return to_route('platform.customers.show', $tenant)->with('success', 'Owner verification email sent.');
    }

    public function sendPasswordReset(PlatformCustomerActionRequest $request, Tenant $tenant): RedirectResponse
    {
        $this->operations->sendOwnerPasswordReset($request->user(), $tenant);

        return to_route('platform.customers.show', $tenant)->with('success', 'Owner password reset email sent.');
    }

    public function createFreeSubscription(PlatformCustomerActionRequest $request, Tenant $tenant): RedirectResponse
    {
        $this->operations->createFreeSubscription($request->user(), $tenant);

        return to_route('platform.customers.show', $tenant)->with('success', 'Free subscription created.');
    }
}
