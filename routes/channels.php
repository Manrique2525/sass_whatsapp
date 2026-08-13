<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Canales privados del tenant: `private-tenant.{tenantId}.conversations.{conversationId}`.
| La autorización SIEMPRE comprueba la pertenencia del usuario al tenant; un
| usuario jamás puede suscribirse a un canal de un tenant ajeno.
|
| NOTA: Laravel solo soporta segmentos fijos y placeholders `{...}` en el patrón
| de canal (no existe comodín `*`); cada recurso del tenant registra su propio
| patrón: `tenant.{tenantId}.<recurso>.{recursoId}`.
|
*/

Broadcast::channel('tenant.{tenantId}.conversations.{conversationId}', function (User $user, string $tenantId, string $conversationId): bool {
    return $user->belongsToTenantById($tenantId);
});
