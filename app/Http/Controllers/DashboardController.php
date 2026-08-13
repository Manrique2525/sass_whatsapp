<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard');
    }
}
