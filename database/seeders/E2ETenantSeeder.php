<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Enums\TenantStatus;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Testing\E2EEnvironmentGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures deterministas del entorno E2E (FASE 30, ADR-110).
 *
 * Crea dos tenants aislados para las pruebas de multi-tenancy y auth:
 *
 *  - Tenant A "E2E Tenant A" (id/constante E2E_TENANT_A_ID):
 *      owner@e2e.local (owner), admin@e2e.local (admin), agent@e2e.local (agent),
 *      switch@e2e.local (owner), + contacto + conversación + mensaje.
 *  - Tenant B "E2E Tenant B" (id/constante E2E_TENANT_B_ID):
 *      tenantb-owner@e2e.local (owner), tenantb-agent@e2e.local (agent),
 *      switch@e2e.local (agent), + contacto.
 *
 * `switch@e2e.local` pertenece a AMBOS tenants con rol distinto para probar
 * el switch de tenant. `owner@e2e.local` pertenece solo a A para probar el
 * aislamiento cross-tenant (nunca debe leer/actuar sobre B).
 *
 * Los ID de tenants/contactos/conversación son UUID DETERMINISTAS para que las
 * pruebas E2E puedan referenciarlos sin consultar la BD. Se duplican en
 * `tests/e2e/helpers/constants.ts`.
 *
 * SOLO se ejecuta bajo el guard E2E (APP_ENV=e2e + BD *_e2e_test).
 */
final class E2ETenantSeeder extends Seeder
{
    public const TENANT_A_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

    public const TENANT_B_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2';

    public const CONTACT_A_ID = 'cccccccc-cccc-4ccc-8ccc-ccccccccccc3';

    public const CONTACT_B_ID = 'dddddddd-dddd-4ddd-8ddd-ddddddddddd4';

    public const CONVERSATION_A_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeee5';

    public function run(): void
    {
        E2EEnvironmentGuard::assertAppEnvironment();
        E2EEnvironmentGuard::assertDatabase();

        $this->call([
            RolesAndPermissionsSeeder::class,
            PlanSeeder::class,
        ]);

        $plan = Plan::query()->where('slug', 'free')->firstOrFail();

        DB::transaction(function () use ($plan): void {
            $this->createTenantA($plan);
            $this->createTenantB($plan);
        });
    }

    private function createTenantA(Plan $plan): void
    {
        $tenant = $this->createTenant(self::TENANT_A_ID, 'E2E Tenant A', 'e2e-tenant-a', $plan);

        $this->makeMember($tenant, 'owner@e2e.local', 'E2E Owner A', UserRole::Owner);
        $this->makeMember($tenant, 'admin@e2e.local', 'E2E Admin A', UserRole::Admin);
        $this->makeMember($tenant, 'agent@e2e.local', 'E2E Agent A', UserRole::Agent);
        $this->makeMember($tenant, 'switch@e2e.local', 'E2E Switch', UserRole::Owner);

        $this->createEntitlement($tenant, $plan);

        $contact = $this->createContact($tenant, self::CONTACT_A_ID, '+15550001001', 'María A', 'maria.a@example.com');
        $conversation = $this->createConversation($tenant, self::CONVERSATION_A_ID, $contact);
        $this->createMessage($tenant, $conversation, 'Hola, ¿me ayudan?');
    }

    private function createTenantB(Plan $plan): void
    {
        $tenant = $this->createTenant(self::TENANT_B_ID, 'E2E Tenant B', 'e2e-tenant-b', $plan);

        $this->makeMember($tenant, 'tenantb-owner@e2e.local', 'E2E Owner B', UserRole::Owner);
        $this->makeMember($tenant, 'tenantb-agent@e2e.local', 'E2E Agent B', UserRole::Agent);
        $this->makeMember($tenant, 'switch@e2e.local', 'E2E Switch', UserRole::Agent);

        $this->createEntitlement($tenant, $plan);

        $contact = $this->createContact($tenant, self::CONTACT_B_ID, '+15550002001', 'Carlos B', 'carlos.b@example.com');
        $this->createConversation($tenant, $this->uuid('representative-b'), $contact);
    }

    private function createTenant(string $id, string $name, string $slug, Plan $plan): Tenant
    {
        $existing = Tenant::query()->find($id);

        if ($existing !== null) {
            return $existing;
        }

        $tenant = new Tenant([
            'name' => $name,
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan_id' => $plan->id,
            'timezone' => 'America/Mexico_City',
            'locale' => 'es',
        ]);

        $tenant->setAttribute('id', $id);
        $tenant->save();

        return $tenant;
    }

    /**
     * Genera un UUID determinista a partir de un seed (reproducible entre runs
     * para IDs que no necesitan ser constantes).
     */
    private function uuid(string $seed): string
    {
        $hash = md5($seed);

        return sprintf(
            '%s-%s-4%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            '8'.substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    /**
     * Crea (o recupera) el usuario con email dado y lo hace miembro ACTIVO del
     * tenant con el rol indicado, dejándolo como su tenant activo.
     */
    private function makeMember(Tenant $tenant, string $email, string $name, UserRole $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => env('E2E_TEST_PASSWORD', 'e2e-password'),
            ],
        );

        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->tenants()->syncWithoutDetaching([$tenant->id => [
            'role' => $role->value,
            'status' => 'active',
            'joined_at' => now(),
        ]]);

        $user->forceFill(['current_tenant_id' => $tenant->id])->save();

        return $user;
    }

    /**
     * Entitlement de uso ilimitado (espejo de `ensure_test_usage_entitlement` de
     * tests, que no está disponible en el runtime e2e no-phpunit).
     */
    private function createEntitlement(Tenant $tenant, Plan $plan): void
    {
        $previousTenantId = TenantContext::id();
        TenantContext::setId($tenant->id);

        try {
            if (Subscription::query()->where('tenant_id', $tenant->id)->exists()) {
                return;
            }

            Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->addMonth()->startOfMonth(),
                'cancel_at_period_end' => false,
                'quantity' => 1,
            ]);
        } finally {
            if ($previousTenantId !== null) {
                TenantContext::setId($previousTenantId);
            } else {
                TenantContext::clear();
            }
        }
    }

    private function createContact(Tenant $tenant, string $id, string $phone, string $name, string $email): Contact
    {
        TenantContext::setId($tenant->id);

        try {
            $existing = Contact::query()->find($id);

            if ($existing !== null) {
                return $existing;
            }

            $contact = new Contact([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
            ]);

            $contact->setAttribute('id', $id);
            $contact->save();

            return $contact;
        } finally {
            TenantContext::clear();
        }
    }

    private function createConversation(Tenant $tenant, string $id, Contact $contact): Conversation
    {
        TenantContext::setId($tenant->id);

        try {
            if (Conversation::query()->find($id) !== null) {
                return Conversation::query()->find($id);
            }

            $conversation = new Conversation([
                'contact_id' => $contact->id,
                'status' => ConversationStatus::Open,
                'auto_assigned' => false,
                'bot_paused' => false,
                'last_message_at' => null,
                'last_interaction_at' => null,
            ]);

            $conversation->setAttribute('id', $id);
            $conversation->save();

            return $conversation;
        } finally {
            TenantContext::clear();
        }
    }

    private function createMessage(Tenant $tenant, Conversation $conversation, string $body): void
    {
        TenantContext::setId($tenant->id);

        try {
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => MessageDirection::Inbound,
                'type' => MessageType::Text,
                'status' => MessageStatus::Pending,
                'body' => $body,
                'sent_at' => now(),
            ]);
        } finally {
            TenantContext::clear();
        }
    }
}
