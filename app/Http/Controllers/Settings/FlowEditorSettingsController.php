<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página del editor visual de flujos (FASE 12). El grafo se carga y guarda vía
 * API (`/api/v1/tenants/{tenant}/flows/{flow}` y `.../draft`) donde la
 * autorización y el multi-tenancy son reales; esta página es solo la envoltura
 * Inertia con los ids para que el frontend haga sus llamadas.
 *
 * `{chatbot}` y `{flow}` se resuelven como string (sin route-model binding):
 * los ids se pasan como props y cualquier validación de pertenencia ocurre en
 * los endpoints de la API (404/403/409).
 */
final class FlowEditorSettingsController extends Controller
{
    public function show(Request $request, string $chatbot, string $flow): Response
    {
        return Inertia::render('Flows/Editor', [
            'chatbotId' => $chatbot,
            'flowId' => $flow,
        ]);
    }
}
