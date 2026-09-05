<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Application\Platform\Services\PlatformCustomerQueryService;
use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformCustomerIndexRequest;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformCustomerController extends Controller
{
    public function __construct(private readonly PlatformCustomerQueryService $customers) {}

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
}
