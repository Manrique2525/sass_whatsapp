<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\InvitationNotification;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppPhoneNumber;
use App\Infrastructure\Tenancy\TenantContext;
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

/**
 * Crea la cuenta de WhatsApp + número de un tenant con el TenantContext activo
 * (igual que en producción lo haría un request autorizado). Devuelve ambos.
 *
 * @param  array<string, mixed>  $accountAttributes
 * @return array{account: WhatsAppAccount, phone: WhatsAppPhoneNumber}
 */
function make_whatsapp_setup(Tenant $tenant, array $accountAttributes = []): array
{
    TenantContext::setId($tenant->id);

    try {
        $account = $tenant->whatsappAccount()->create(array_merge([
            'whatsapp_business_account_id' => 'waba-1',
            'access_token' => 'token-del-tenant',
            'status' => 'connected',
        ], $accountAttributes));

        $phone = $tenant->whatsappPhoneNumbers()->create([
            'whatsapp_account_id' => $account->id,
            'phone_id' => 'phone-1',
            'display_phone_number' => '15550000002',
            'verified_name' => 'Negocio Central',
            'quality_rating' => 'GREEN',
            'status' => 'connected',
            'is_default' => true,
        ]);
    } finally {
        TenantContext::clear();
    }

    return ['account' => $account, 'phone' => $phone];
}
