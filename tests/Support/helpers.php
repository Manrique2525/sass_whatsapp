<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\InvitationNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla de `ScopedWidget` si no existe (por test, dentro de la
 * transacción de RefreshDatabase; se recrea al inicio de cada test).
 */
function create_scoped_widgets_table(): void
{
    if (Schema::hasTable('scoped_widgets')) {
        return;
    }

    Schema::create('scoped_widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('tenant_id');
        $table->string('name');
        $table->timestamps();
    });
}

/**
 * Inserta un widget directamente (sin pasar por el hook de `BelongsToTenant`)
 * simulando un registro creado por el tenant indicado.
 */
function insert_scoped_widget(string $tenantId, string $name): void
{
    DB::table('scoped_widgets')->insert([
        'tenant_id' => $tenantId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Hace al usuario miembro ACTIVO del tenant y lo deja como tenant activo.
 */
function make_tenant_member(User $user, Tenant $tenant, string $role): void
{
    $user->tenants()->attach($tenant, [
        'role' => $role,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $user->forceFill(['current_tenant_id' => $tenant->id])->save();
}

/**
 * Ejecuta la operación que crea/reenvía una invitación bajo `Notification::fake`
 * y devuelve el token PLANO (para poder usar los endpoints de la invitación).
 */
function invitation_token(Closure $operation): string
{
    $token = null;

    Notification::fake();

    $operation();

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        function (InvitationNotification $notification) use (&$token): bool {
            $token = $notification->getToken();

            return true;
        },
    );

    if ($token === null) {
        throw new LogicException('El token de invitación no fue capturado.');
    }

    return $token;
}
