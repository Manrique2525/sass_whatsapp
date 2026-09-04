<?php

declare(strict_types=1);

use App\Application\Tenants\Services\ProvisionNewWorkspace;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * El registro web (FASE 33 U1, ADR-124) ahora provisiona el workspace del
 * usuario en el POST /register: crea el tenant, la membresía owner, fija
 * current_tenant_id y concede el plan free (suscripción activa). Los tests que
 * llegan a la provisión necesitan el catálogo de roles + el plan free.
 */
function seed_register_catalog(): void
{
    test()->seed(RolesAndPermissionsSeeder::class);

    Plan::query()->firstOrCreate(
        ['slug' => 'free'],
        [
            'name' => 'Free',
            'description' => 'Free tier with basic limits',
            'is_active' => true,
            'price_monthly' => 0,
            'price_yearly' => 0,
            'limits' => [
                'messages' => 100,
                'ai_tokens' => 1000,
                'contacts' => 50,
                'flow_executions' => 10,
                'users' => 3,
                'knowledge_documents' => 2,
            ],
            'features' => ['ai_enabled' => false],
            'sort_order' => 0,
        ],
    );
}

test('la pantalla de registro se renderiza', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Register'));
});

test('un nuevo usuario puede registrarse', function (): void {
    seed_register_catalog();

    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'jane@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('password123');

    $this->assertAuthenticatedAs($user);
});

test('el email del usuario se guarda en minúsculas y recortado', function (): void {
    seed_register_catalog();

    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => '  Jane@Example.COM ',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    expect(User::query()->where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('no se permite registrar un email duplicado', function (): void {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'dup@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

test('se valida la longitud mínima de la contraseña', function (): void {
    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'short@example.com',
        'password' => '123',
        'password_confirmation' => '123',
    ])->assertSessionHasErrors('password');
});

test('el registro está limitado por tasa', function (): void {
    seed_register_catalog();

    for ($i = 0; $i < 6; $i++) {
        $this->post('/register', [
            'name' => 'Spam',
            'email' => "spam{$i}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }

    $this->post('/register', [
        'name' => 'Spam',
        'email' => 'spam6@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| FASE 33 U1 — provisión de workspace en el registro (ADR-124)
|--------------------------------------------------------------------------
*/

test('REG-PROV-01: el registro provisiona el workspace, la membresía owner y el plan free', function (): void {
    seed_register_catalog();

    $this->post('/register', [
        'name' => 'Jane Doe',
        'email' => 'prov@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'prov@example.com')->first();

    expect($user->current_tenant_id)->not->toBeNull();

    $tenant = Tenant::query()->find($user->current_tenant_id);

    expect($tenant)->not->toBeNull()
        ->and($tenant->status->value)->toBe('active')
        ->and($tenant->name)->toBe('Jane Doe');

    // Membresía owner ACTIVA en tenant_users (fuente de verdad).
    $this->assertDatabaseHas('tenant_users', [
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'status' => 'active',
    ]);

    // El slug se autogeneró (collision-safe) y la UNIQUE se respeta.
    expect($tenant->slug)->not->toBeNull();
});

test('REG-PROV-02: el slug es único y collision-safe entre registros del mismo nombre', function (): void {
    seed_register_catalog();

    $first = User::factory()->create(['name' => 'John Smith', 'email' => 'js1@example.com']);
    $second = User::factory()->create(['name' => 'John Smith', 'email' => 'js2@example.com']);

    $provision = test()->app(ProvisionNewWorkspace::class);
    $tenantA = $provision->provision($first);
    $tenantB = $provision->provision($second);

    expect($tenantA->slug)->not->toBe($tenantB->slug);
    expect(Tenant::query()->where('slug', $tenantA->slug)->count())->toBe(1);
    expect(Tenant::query()->where('slug', $tenantB->slug)->count())->toBe(1);
});

test('REG-PROV-03: el plan free se concede con una suscripción ACTIVA', function (): void {
    seed_register_catalog();

    $this->post('/register', [
        'name' => 'Plan Doe',
        'email' => 'plan@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'plan@example.com')->first();
    $tenant = Tenant::query()->find($user->current_tenant_id);

    $this->assertDatabaseHas('subscriptions', [
        'tenant_id' => $tenant->id,
        'status' => 'active',
    ]);

    $subscription = Subscription::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan->slug)->toBe('free');

    // La suscripción (fuente de verdad) está en sync con el denormalizado.
    expect($tenant->plan_id)->toBe($subscription->plan_id);
});

test('REG-PROV-04: cada workspace queda aislado y solo su owner lo accede', function (): void {
    seed_register_catalog();

    $this->post('/register', [
        'name' => 'Owner A',
        'email' => 'owner-a@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    $userA = User::query()->where('email', 'owner-a@example.com')->first();
    $tenantA = Tenant::query()->find($userA->current_tenant_id);

    // Un usuario no miembro no pertenece al workspace de A y no lo ve.
    $stranger = User::factory()->create();

    expect($stranger->belongsToTenant($tenantA))->toBeFalse();
    expect($stranger->tenants()->where('tenants.id', $tenantA->id)->exists())->toBeFalse();
    expect($stranger->current_tenant_id)->toBeNull();
});

test('REG-PROV-05: el rol owner se materializa en spatie scopeado al tenant', function (): void {
    seed_register_catalog();

    $this->post('/register', [
        'name' => 'Role Doe',
        'email' => 'role@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'role@example.com')->first();
    $tenant = Tenant::query()->find($user->current_tenant_id);

    expect($user->roleForTenant($tenant->id))->toBe(UserRole::Owner);
    expect($user->isCurrentTenant($tenant))->toBeTrue();
});

test('REG-PROV-06: el onboarding se sirve al owner verificado con tenant', function (): void {
    seed_register_catalog();

    $this->post('/register', [
        'name' => 'Onboard Doe',
        'email' => 'onboard@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'onboard@example.com')->first();
    $user->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($user)
        ->get('/onboarding')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Onboarding/Index'));
});
