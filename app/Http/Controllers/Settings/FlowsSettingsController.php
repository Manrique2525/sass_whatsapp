<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de flujos (vista read-only) del tenant. El contenido se carga vía API
 * (`/api/v1/tenants/{tenant}/chatbots`, `.../flows`) donde la autorización es
 * real (Flows.View / Flows.Manage); esta página es solo la envoltura Inertia.
 */
final class FlowsSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Settings/Flows');
    }
}
