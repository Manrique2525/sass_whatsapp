<?php

declare(strict_types=1);

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Flows\Enums\VariableType;
use App\Domain\Flows\Services\VariableCatalogService;
use App\Domain\Flows\ValueObjects\VariableDefinition;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Crea un flujo con el grafo dado y devuelve el catálogo indexado por clave.
 *
 * @param  array<int, array{id: string, type: string, name?: string, config?: array<string, mixed>, is_start?: bool}>  $nodes
 * @param  array<int, array{from: string, to: string, label?: string|null}>  $connections
 * @return array<string, VariableDefinition>
 */
function variable_catalog_for_graph(array $nodes, array $connections): array
{
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    make_flow_graph($flow, $nodes, $connections);

    TenantContext::setId($tenant->id);

    try {
        return collect(app(VariableCatalogService::class)->forFlow($flow))
            ->keyBy('key')
            ->all();
    } finally {
        TenantContext::clear();
    }
}

test('VAR-5: el catálogo incluye contact y conversation en solo lectura', function (): void {
    $catalog = variable_catalog_for_graph([], []);

    $name = $catalog['contact.name'];
    $conversation = $catalog['conversation.id'];

    expect($name->namespace)->toBe('contact')
        ->and($name->type)->toBe(VariableType::String)
        ->and($name->writable)->toBeFalse()
        ->and($catalog['contact.email']->writable)->toBeFalse()
        ->and($catalog['contact.phone']->writable)->toBeFalse()
        ->and($conversation->namespace)->toBe('conversation')
        ->and($conversation->writable)->toBeFalse()
        ->and($conversation->key)->toBe('conversation.id');
});

test('VAR-5: business solo expone la whitelist PUBLIC_FIELDS (sin secretos)', function (): void {
    $catalog = variable_catalog_for_graph([], []);
    $keys = array_keys($catalog);

    foreach (BusinessProfile::PUBLIC_FIELDS as $field) {
        expect($catalog['business.'.$field])->not->toBeNull()
            ->and($catalog['business.'.$field]->writable)->toBeFalse();
    }

    expect($catalog)->toHaveCount(3 + count(BusinessProfile::PUBLIC_FIELDS) + 1)
        ->and($catalog['business.access_token'] ?? null)->toBeNull()
        ->and($catalog['business.token'] ?? null)->toBeNull()
        ->and($catalog['business.tenant_id'] ?? null)->toBeNull();
});

test('VAR-5: custom se deriva de los nodos question con tipo y default', function (): void {
    $catalog = variable_catalog_for_graph([
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Edad', 'config' => [
            'prompt' => '¿Edad?',
            'field' => 'edad',
            'type' => 'integer',
            'default' => 0,
        ]],
        ['id' => 'n3', 'type' => 'question', 'name' => 'Cliente VIP', 'config' => [
            'prompt' => '¿VIP?',
            'field' => 'vip',
            'type' => 'boolean',
        ]],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    $edad = $catalog['custom.edad'];
    $vip = $catalog['custom.vip'];

    expect($edad->namespace)->toBe('custom')
        ->and($edad->type)->toBe(VariableType::Integer)
        ->and($edad->default)->toBe(0)
        ->and($edad->writable)->toBeTrue()
        ->and($edad->source)->toBe('question:Edad')
        ->and($vip->type)->toBe(VariableType::Boolean)
        ->and($vip->label)->toBe('Cliente VIP')
        ->and($vip->writable)->toBeTrue();
});

test('VAR-5: sin type declarado el custom es string', function (): void {
    $catalog = variable_catalog_for_graph([
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Nombre', 'config' => ['prompt' => '¿Nombre?', 'field' => 'nombre']],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    expect($catalog['custom.nombre']->type)->toBe(VariableType::String);
});

test('VAR-5: los nodos question con field peligroso o sin patrón se omiten del catálogo', function (): void {
    $catalog = variable_catalog_for_graph([
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Mayúsculas', 'config' => ['prompt' => '?', 'field' => 'Nombre']],
        ['id' => 'n3', 'type' => 'question', 'name' => 'Peligro', 'config' => ['prompt' => '?', 'field' => '__proto__']],
        ['id' => 'n4', 'type' => 'question', 'name' => 'Rara', 'config' => ['prompt' => '?', 'field' => 'mal campo']],
        ['id' => 'n5', 'type' => 'question', 'name' => 'Bien', 'config' => ['prompt' => '?', 'field' => 'clave']],
        ['id' => 'n6', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
        ['from' => 'n4', 'to' => 'n5'],
        ['from' => 'n5', 'to' => 'n6'],
    ]);

    expect($catalog['custom.__proto__'] ?? null)->toBeNull()
        ->and($catalog['custom.clave'])->not->toBeNull()
        ->and($catalog['custom.mal campo'] ?? null)->toBeNull();

    // El field en mayúsculas se normaliza a minúsculas (mismo comportamiento
    // que la captura del motor): aparece como custom.nombre.
    expect($catalog['custom.nombre'])->not->toBeNull()
        ->and($catalog['custom.nombre']->source)->toBe('question:Mayúsculas');
});

test('VAR-5: claves custom duplicadas se colapsan (la primera gana)', function (): void {
    $catalog = variable_catalog_for_graph([
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Uno', 'config' => ['prompt' => '?', 'field' => 'clave', 'type' => 'integer']],
        ['id' => 'n3', 'type' => 'question', 'name' => 'Dos', 'config' => ['prompt' => '?', 'field' => 'clave', 'type' => 'string']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    expect($catalog['custom.clave']->type)->toBe(VariableType::Integer)
        ->and($catalog['custom.clave']->source)->toBe('question:Uno');
});
