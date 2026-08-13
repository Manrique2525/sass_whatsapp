<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de conexión de WhatsApp del tenant. El contenido se carga vía API
 * (`/api/v1/tenants/{tenant}/whatsapp`) donde la autorización es real; esta
 * página es solo la envoltura Inertia.
 */
final class WhatsAppSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Settings/WhatsApp');
    }
}
