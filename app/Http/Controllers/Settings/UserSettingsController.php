<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de usuarios/roles del tenant. El contenido se carga vía API
 * (`/api/v1/tenants/{tenant}/users...`) donde la autorización es real; esta
 * página es solo la envoltura Inertia.
 */
final class UserSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Settings/Users');
    }
}
