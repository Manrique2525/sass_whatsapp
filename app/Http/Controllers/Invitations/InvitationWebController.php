<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública de aceptación de invitación. Los datos reales se obtienen del
 * endpoint público `GET /api/v1/invitations/{token}`; el token plano viaja
 * solo en el enlace del email (ADR-027).
 */
final class InvitationWebController extends Controller
{
    public function show(string $token): Response
    {
        return Inertia::render('Invitations/Accept', [
            'token' => $token,
        ]);
    }
}
