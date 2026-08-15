<?php

declare(strict_types=1);

use App\Application\Flows\Services\FlowEngine;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Services\VariableCatalogService;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 13 — UNIDAD 5 — AISLAMIENTO MULTI-TENANCY (VAR-29/30)
|--------------------------------------------------------------------------
| Ningún dato de un tenant puede ser leído, resuelto o enviado por otro
| tenant. Se verifica a nivel motor (contact/business/custom), a nivel
| catálogo, a nivel HTTP (404) y a nivel webhook (payload del tenant A nunca
| contiene datos del tenant B).
*/

function make_business_profile(Tenant $tenant, string $name): void
{
    TenantContext::setId($tenant->id);

    try {
        $tenant->businessProfile()->create(['name' => $name]);
    } finally {
        TenantContext::clear();
    }
}

function isolation_publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function isolation_run_engine(Tenant $tenant, Message $message, Conversation $conversation): void
{
    TenantContext::setId($tenant->id);

    try {
        app(FlowEngine::class)->handleMessage($tenant, $message, $conversation);
    } finally {
        TenantContext::clear();
    }
}

/**
 * @return list<string>
 */
function isolation_custom_keys(TestResponse $response): array
{
    return collect($response->json('variables'))
        ->filter(static fn (array $definition): bool => str_starts_with((string) $definition['key'], 'custom.'))
        ->map(static fn (array $definition): string => $definition['key'])
        ->values()
        ->all();
}

function isolation_engine_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function isolation_outbound(Tenant $tenant, string $conversationId)
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversationId)
        ->where('direction', MessageDirection::Outbound->value);
}

test('VAR-29: el motor del tenant A jamás resuelve contact/business/custom del tenant B', function (): void {
    Queue::fake();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    make_business_profile($tenantA, 'Negocio-A');
    make_business_profile($tenantB, 'Negocio-B');
    make_contact($tenantB, ['name' => 'Contacto-B', 'metadata' => ['ciudad' => 'Cordoba-B']]);

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);

    isolation_publish_flow($flowA, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Nombre', 'config' => ['prompt' => '¿Nombre?', 'field' => 'nombre']],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Resumen', 'config' => [
            'text' => '{{contact.name}}|{{business.name}}|{{custom.nombre}}|{{contact.ciudad}}',
        ]],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    make_trigger($flowA, ['type' => FlowTriggerType::Start->value]);

    $message = make_inbound_message($tenantA, 'Hola', '15550000001');
    $conversation = isolation_engine_conversation_for($message);

    TenantContext::setId($tenantA->id);
    try {
        $conversation->contact->forceFill(['name' => 'Contacto-A', 'metadata' => ['ciudad' => 'Cordoba-A']])->save();
    } finally {
        TenantContext::clear();
    }

    isolation_run_engine($tenantA, $message, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenantA->id)->firstOrFail();
    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    $answer = make_inbound_message($tenantA, 'Ana', '15550000001');
    $conversation->refresh();
    isolation_run_engine($tenantA, $answer, $conversation);

    $body = (string) isolation_outbound($tenantA, $conversation->id)->orderBy('created_at')->get()->last()?->body;

    expect($body)->toBe('Contacto-A|Negocio-A|Ana|Cordoba-A')
        ->and($body)->not->toContain('Contacto-B')
        ->and($body)->not->toContain('Negocio-B')
        ->and($body)->not->toContain('Cordoba-B');
});

test('VAR-30: el catálogo del tenant A excluye las variables custom del tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenantA, 'owner');

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);
    make_flow_graph($flowA, [
        ['id' => 'a1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'a2', 'type' => 'question', 'name' => 'Campo', 'config' => ['prompt' => '?', 'field' => 'a']],
        ['id' => 'a3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'a1', 'to' => 'a2'],
        ['from' => 'a2', 'to' => 'a3'],
    ]);

    $chatbotB = make_chatbot($tenantB);
    $flowB = make_flow($tenantB, $chatbotB);
    make_flow_graph($flowB, [
        ['id' => 'b1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'b2', 'type' => 'question', 'name' => 'Secreto', 'config' => ['prompt' => '?', 'field' => 'secreto_b']],
        ['id' => 'b3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'b1', 'to' => 'b2'],
        ['from' => 'b2', 'to' => 'b3'],
    ]);

    $response = $this->actingAs($owner)->getJson('/api/v1/tenants/'.$tenantA->id.'/flows/'.$flowA->id.'/variables');

    $response->assertOk();

    expect(isolation_custom_keys($response))->toBe(['custom.a'])
        ->and(isolation_custom_keys($response))->not->toContain('custom.secreto_b');
});

test('VAR-30: pedir el flow del tenant B desde el tenant A responde 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $owner = User::factory()->create();
    make_tenant_member($owner, $tenantA, 'owner');

    $chatbotB = make_chatbot($tenantB);
    $flowB = make_flow($tenantB, $chatbotB);

    $this->actingAs($owner)
        ->getJson('/api/v1/tenants/'.$tenantA->id.'/flows/'.$flowB->id)
        ->assertNotFound();
});

test('VAR-30: con TenantContext del tenant B no se leen datos del flow del tenant A', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);
    make_flow_graph($flowA, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Campo', 'config' => ['prompt' => '?', 'field' => 'a']],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    TenantContext::setId($tenantB->id);

    try {
        $catalog = app(VariableCatalogService::class)->forFlow($flowA);

        // El scope global del tenant B excluye los nodos del flow A: no hay
        // custom.* y el flow A no se re-obtiene bajo el contexto equivocado.
        expect(collect($catalog)->map(static fn ($definition): string => $definition->key)->all())
            ->not->toContain('custom.a');

        expect(Flow::query()->whereKey($flowA->id)->first())->toBeNull();
    } finally {
        TenantContext::clear();
    }
});

test('VAR-30: el webhook del tenant A recibe payload del tenant A, nunca datos del tenant B', function (): void {
    Queue::fake();
    Http::fake(['https://example.com/hook-a*' => Http::response(['ok' => true], 200)]);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    make_business_profile($tenantA, 'Negocio-A');
    make_business_profile($tenantB, 'Negocio-B');
    make_contact($tenantB, ['name' => 'Contacto-B']);

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);

    isolation_publish_flow($flowA, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Nombre', 'config' => ['prompt' => '¿Nombre?', 'field' => 'nombre']],
        ['id' => 'n3', 'type' => 'webhook', 'name' => 'Hook', 'config' => [
            'url' => 'https://example.com/hook-a',
            'method' => 'POST',
            'payload' => [
                'nombre' => '{{custom.nombre}}',
                'negocio' => '{{business.name}}',
                'contacto' => '{{contact.name}}',
            ],
        ]],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    make_trigger($flowA, ['type' => FlowTriggerType::Start->value]);

    $message = make_inbound_message($tenantA, 'Hola', '15550000001');
    $conversation = isolation_engine_conversation_for($message);

    TenantContext::setId($tenantA->id);
    try {
        $conversation->contact->forceFill(['name' => 'Contacto-A'])->save();
    } finally {
        TenantContext::clear();
    }

    isolation_run_engine($tenantA, $message, $conversation);

    $answer = make_inbound_message($tenantA, 'Ana', '15550000001');
    $conversation->refresh();
    isolation_run_engine($tenantA, $answer, $conversation);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return str_starts_with($request->url(), 'https://example.com/hook-a')
            && $payload['nombre'] === 'Ana'
            && $payload['negocio'] === 'Negocio-A'
            && $payload['contacto'] === 'Contacto-A'
            && ! str_contains((string) json_encode($payload), 'Negocio-B')
            && ! str_contains((string) json_encode($payload), 'Contacto-B');
    });
});
