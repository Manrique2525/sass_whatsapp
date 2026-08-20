<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class FaqSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Settings/Faq');
    }
}
