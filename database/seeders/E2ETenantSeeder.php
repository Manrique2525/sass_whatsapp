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
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WhatsAppAccountStatus;
use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Testing\E2EEnvironmentGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
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

    public const CONTACT_A2_ID = 'cccccccc-cccc-4ccc-8ccc-ccccccccccd2';

    public const CONVERSATION_A2_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeed2';

    /** Contacto/conversación limpia para el journey de handoff humano (FASE 30 U2). */
    public const CONTACT_HANDOFF_ID = 'cccccccc-cccc-4ccc-8ccc-ccccccccccd3';

    public const CONVERSATION_HANDOFF_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeed3';

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
        $agent = $this->makeMember($tenant, 'agent@e2e.local', 'E2E Agent A', UserRole::Agent);
        $this->makeMember($tenant, 'switch@e2e.local', 'E2E Switch', UserRole::Owner);

        $this->createEntitlement($tenant, $plan);
        $this->createConnectedWhatsAppSetup($tenant);

        $contact = $this->createContact($tenant, self::CONTACT_A_ID, '+15550001001', 'María A', 'maria.a@example.com');
        $conversation = $this->createConversation($tenant, self::CONVERSATION_A_ID, $contact);
        $this->createMessage($tenant, $conversation, 'Hola, ¿me ayudan?', [
            'created_at' => now()->subMinutes(30),
            'sent_at' => now()->subMinutes(30),
        ]);
        $this->createMessage($tenant, $conversation, '¿Tienen el plan pro?', [
            'created_at' => now()->subMinutes(20),
            'sent_at' => now()->subMinutes(20),
        ]);
        $this->createMessage($tenant, $conversation, 'Perfecto, muchas gracias.', [
            'created_at' => now()->subMinutes(10),
            'sent_at' => now()->subMinutes(10),
        ]);
        $conversation->forceFill([
            'last_message_at' => now()->subMinutes(10),
            'last_interaction_at' => now()->subMinutes(10),
        ])->save();

        // Conversación asignada al agente (escenario de reply del agente).
        $contactA2 = $this->createContact($tenant, self::CONTACT_A2_ID, '+15550001002', 'Juan A2', 'juan.a2@example.com');
        $conversationA2 = $this->createConversation($tenant, self::CONVERSATION_A2_ID, $contactA2);
        $conversationA2->forceFill([
            'agent_id' => $agent->id,
            'auto_assigned' => false,
            'last_message_at' => now()->subMinutes(5),
            'last_interaction_at' => now()->subMinutes(5),
        ])->save();
        $this->createMessage($tenant, $conversationA2, 'Hola, ¿me ayudan con mi pedido?', [
            'created_at' => now()->subMinutes(5),
            'sent_at' => now()->subMinutes(5),
        ]);

        // Conversación limpia para el handoff humano: el setup (SetupE2EEnvironment)
        // crea el chatbot+flujo y dispara el FlowEngine real (Start -> Human ->
        // HumanHandoffService). Estado inicial: bot activo, sin mensajes.
        $contactHandoff = $this->createContact($tenant, self::CONTACT_HANDOFF_ID, '+15550001003', 'Rosa Handoff', 'rosa.handoff@example.com');
        $this->createConversation($tenant, self::CONVERSATION_HANDOFF_ID, $contactHandoff);
    }

    private function createTenantB(Plan $plan): void
    {
        $tenant = $this->createTenant(self::TENANT_B_ID, 'E2E Tenant B', 'e2e-tenant-b', $plan);

        $this->makeMember($tenant, 'tenantb-owner@e2e.local', 'E2E Owner B', UserRole::Owner);
        $this->makeMember($tenant, 'tenantb-agent@e2e.local', 'E2E Agent B', UserRole::Agent);
        $this->makeMember($tenant, 'switch@e2e.local', 'E2E Switch', UserRole::Agent);

        $this->createEntitlement($tenant, $plan);

        $contact = $this->createContact($tenant, self::CONTACT_B_ID, '+15550002001', 'Carlos B', 'carlos.b@example.com');
        $conversationB = $this->createConversation($tenant, $this->uuid('representative-b'), $contact);
        $this->createMessage($tenant, $conversationB, 'Hola, ¿hay stock disponible?', [
            'created_at' => now()->subMinutes(40),
            'sent_at' => now()->subMinutes(40),
        ]);
        $conversationB->forceFill([
            'last_message_at' => now()->subMinutes(40),
            'last_interaction_at' => now()->subMinutes(40),
        ])->save();
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

    /**
     * @param  array{created_at?: Carbon|null, sent_at?: Carbon|null, direction?: string|MessageDirection, status?: string|MessageStatus, metadata?: array<string, mixed>}  $options
     */
    private function createMessage(Tenant $tenant, Conversation $conversation, string $body, array $options = []): Message
    {
        TenantContext::setId($tenant->id);

        try {
            $message = new Message([
                'conversation_id' => $conversation->id,
                'direction' => $options['direction'] ?? MessageDirection::Inbound,
                'type' => MessageType::Text,
                'status' => $options['status'] ?? MessageStatus::Sent,
                'body' => $body,
                'sent_at' => $options['sent_at'] ?? now(),
                'metadata' => $options['metadata'] ?? null,
            ]);

            $message->created_at = $options['created_at'] ?? now();
            $message->updated_at = $options['created_at'] ?? now();
            $message->save();

            return $message;
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * Cuenta WhatsApp del tenant A CONECTADA (datos sintéticos E2E, nunca reales):
     * registros mínimos válidos que exige el pipeline real de envío
     * (SendWhatsAppMessage: account connected + phone connected + token no vacío).
     * Tenant B NO obtiene cuenta conectada (aislamiento: whatsapp_not_connected).
     */
    private function createConnectedWhatsAppSetup(Tenant $tenant): void
    {
        TenantContext::setId($tenant->id);

        try {
            $account = $tenant->whatsappAccount()->create([
                'whatsapp_business_account_id' => 'waba-e2e-'.$tenant->slug,
                'display_name' => 'E2E Negocio A',
                'access_token' => env('E2E_WHATSAPP_TOKEN', 'e2e-'.str_repeat('a', 24)),
                'status' => WhatsAppAccountStatus::Connected,
            ]);

            $tenant->whatsappPhoneNumbers()->create([
                'whatsapp_account_id' => $account->id,
                'phone_id' => 'phone-e2e-'.$tenant->slug,
                'display_phone_number' => '+15550009999',
                'verified_name' => 'E2E Negocio A',
                'quality_rating' => 'GREEN',
                'status' => PhoneNumberStatus::Connected,
                'is_default' => true,
            ]);
        } finally {
            TenantContext::clear();
        }
    }
}
