<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Página de onboarding post-registro (FASE 33 U1, ADR-124).
 *
 * Solo es una envoltura Inertia: el estado real (workspace, rol, plan free,
 * permisos) se expone vía props compartidas por `HandleInertiaRequests` y el
 * detalle de la suscripción se lee vía API (`GET /api/v1/tenants/{tenant}/subscriptions`),
 * igual que en el resto de páginas de tenant. No escribe nada: no atrapa al
 * usuario, puede salir hacia `/dashboard`.
 *
 * Rutas con middleware `['verified', 'tenant']`: el usuario ya tiene tenant
 * provisionado en el signup y email verificado.
 */
final class OnboardingController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Onboarding/Index');
    }
}
