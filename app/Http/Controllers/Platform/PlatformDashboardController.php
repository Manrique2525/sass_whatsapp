<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Application\Platform\Services\PlatformDashboardQueryService;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformDashboardController extends Controller
{
    public function __construct(private readonly PlatformDashboardQueryService $dashboard) {}

    public function __invoke(): Response
    {
        return Inertia::render('Platform/Dashboard', [
            'dashboard' => $this->dashboard->overview(),
        ]);
    }
}
