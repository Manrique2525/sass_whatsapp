<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\Enums\WhatsAppTemplateStatus;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppTemplate;
use App\Domain\WhatsApp\ValueObjects\TemplateInfo;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Fakes\FakeMediaTemplateProvider;

uses(RefreshDatabase::class);

/**
 * Crea un template aprobado del tenant/account (o con atributos custom).
 */
function make_template(Tenant $tenant, WhatsAppAccount $account, array $attributes = []): WhatsAppTemplate
{
    $placeholders = (int) ($attributes['placeholders'] ?? 1);
    unset($attributes['placeholders']);

    $tokens = [];
    if ($placeholders > 0) {
        for ($i = 1; $i <= $placeholders; $i++) {
            $tokens[] = '{{'.$i.'}}';
        }
    }

    return TenantContext::withId($tenant->id, function () use ($tenant, $account, $attributes, $tokens): WhatsAppTemplate {
        $template = new WhatsAppTemplate(array_merge([
            'whatsapp_account_id' => $account->id,
            'provider_template_id' => 'tmpl-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'hola',
            'language' => 'en_US',
            'category' => 'utility',
            'status' => WhatsAppTemplateStatus::Approved->value,
            'components' => [['type' => 'BODY', 'text' => count($tokens) === 0 ? 'Hola' : 'Hola '.implode(' ', $tokens)]],
            'last_synced_at' => now(),
        ], $attributes));

        $template->forceFill(['tenant_id' => $tenant->id]);
        $template->save();

        return $template;
    });
}

function send_template_url(Tenant $tenant, string $conversationId): string
{
    return "/api/v1/tenants/{$tenant->id}/conversations/{$conversationId}/templates/send";
}

test('TEMPLATE-1: index lista solo los templates del tenant propio', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    $setup = make_whatsapp_setup($tenant);
    make_template($tenant, $setup['account']);

    $this->actingAs($user)
        ->get("/api/v1/tenants/{$tenant->id}/whatsapp/templates")
        ->assertOk()
        ->assertJsonCount(1, 'templates')
        ->assertJsonPath('templates.0.name', 'hola');
});

test('TEMPLATE-2: CRITICO — index de OTRO tenant NO filtra templates ajenos', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userB = User::factory()->create();
    make_tenant_member($userB, $tenantB, 'owner');

    make_template($tenantA, make_whatsapp_setup($tenantA)['account']);

    $this->actingAs($userB)
        ->get("/api/v1/tenants/{$tenantB->id}/whatsapp/templates")
        ->assertOk()
        ->assertJsonCount(0, 'templates');
});

test('TEMPLATE-3: sync materializa el catálogo de Meta en la BD (upsert)', function (): void {
    $fake = new FakeMediaTemplateProvider;
    $fake->setTemplateCatalog([
        new TemplateInfo('hola', 'en_US', 'utility', 'approved', 't-1', [['type' => 'BODY', 'text' => 'Hola {{1}}']]),
        new TemplateInfo('recibo', 'en_US', 'utility', 'pending', 't-2', [['type' => 'BODY', 'text' => 'Recibo']]),
    ]);
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    $setup = make_whatsapp_setup($tenant);

    $this->actingAs($user)
        ->post("/api/v1/tenants/{$tenant->id}/whatsapp/accounts/{$setup['account']->id}/templates/sync")
        ->assertOk()
        ->assertJsonPath('synced', 2);

    expect(WhatsAppTemplate::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(2);
    expect(WhatsAppTemplate::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'hola')
        ->first()?->status)->toBe(WhatsAppTemplateStatus::Approved);
});

test('TEMPLATE-4: send approved encola envío y crea mensaje outbound template', function (): void {
    $fake = new FakeMediaTemplateProvider;
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    ensure_test_usage_entitlement($tenant);
    $setup = make_whatsapp_setup($tenant);
    $template = make_template($tenant, $setup['account'], ['placeholders' => 2]);
    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($user)
        ->post(send_template_url($tenant, $conversation->id), [
            'template_id' => $template->id,
            'variables' => ['Juan', 'Pérez'],
        ])
        ->assertCreated()
        ->assertJsonPath('created_message.type', MessageType::Template->value);

    $outbound = Message::query()->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('direction', MessageDirection::Outbound->value)
        ->firstOrFail();

    expect($fake->sendTemplateCalls())->toBe(1)
        ->and($fake->lastTemplateName)->toBe('hola')
        ->and($fake->lastTemplateLanguage)->toBe('en_US')
        ->and($fake->lastTemplateParams)->toBe([['type' => 'text', 'text' => 'Juan'], ['type' => 'text', 'text' => 'Pérez']])
        ->and($outbound->type)->toBe(MessageType::Template)
        ->and($outbound->metadata['template_name'])->toBe('hola');
});

test('TEMPLATE-5: send de template NO aprobado responde 409 y NO llama a Meta', function (): void {
    $fake = new FakeMediaTemplateProvider;
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    ensure_test_usage_entitlement($tenant);
    $setup = make_whatsapp_setup($tenant);
    $template = make_template($tenant, $setup['account'], ['status' => WhatsAppTemplateStatus::Pending->value]);
    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($user)
        ->post(send_template_url($tenant, $conversation->id), [
            'template_id' => $template->id,
            'variables' => ['Juan'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TEMPLATE_SEND_REJECTED');

    expect($fake->sendTemplateCalls())->toBe(0);
    expect(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('TEMPLATE-6: variables inválidas (faltantes) responde 422 y NO crea mensaje', function (): void {
    $fake = new FakeMediaTemplateProvider;
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    ensure_test_usage_entitlement($tenant);
    $setup = make_whatsapp_setup($tenant);
    $template = make_template($tenant, $setup['account'], ['placeholders' => 2]);
    $conversation = make_conversation($tenant, make_contact($tenant));

    $this->actingAs($user)
        ->post(send_template_url($tenant, $conversation->id), [
            'template_id' => $template->id,
            'variables' => ['solo-una'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TEMPLATE_SEND_REJECTED');

    expect($fake->sendTemplateCalls())->toBe(0);
    expect(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('TEMPLATE-7: CRITICO — template de OTRO tenant responde 404 sin tocar Meta', function (): void {
    $fake = new FakeMediaTemplateProvider;
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenantA = Tenant::factory()->create();
    $setupA = make_whatsapp_setup($tenantA);
    $templateA = make_template($tenantA, $setupA['account'], ['status' => WhatsAppTemplateStatus::Approved->value]);

    $tenantB = Tenant::factory()->create();
    $userB = User::factory()->create();
    make_tenant_member($userB, $tenantB, 'owner');
    ensure_test_usage_entitlement($tenantB);
    $conversationB = make_conversation($tenantB, make_contact($tenantB));

    $this->actingAs($userB)
        ->post(send_template_url($tenantB, $conversationB->id), [
            'template_id' => $templateA->id,
            'variables' => ['Juan'],
        ])
        ->assertNotFound();

    expect($fake->sendTemplateCalls())->toBe(0);
});

test('TEMPLATE-8: template approved se envía incluso fuera de la ventana de 24h', function (): void {
    $fake = new FakeMediaTemplateProvider;
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');
    ensure_test_usage_entitlement($tenant);
    $setup = make_whatsapp_setup($tenant);
    $template = make_template($tenant, $setup['account']);
    $old = Carbon::now()->subDays(10);
    $conversation = make_conversation($tenant, make_contact($tenant), [
        'last_message_at' => $old,
        'last_interaction_at' => $old,
    ]);

    $this->actingAs($user)
        ->post(send_template_url($tenant, $conversation->id), [
            'template_id' => $template->id,
            'variables' => ['Ana'],
        ])
        ->assertCreated();

    expect($fake->sendTemplateCalls())->toBe(1);
});
