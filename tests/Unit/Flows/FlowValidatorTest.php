<?php

declare(strict_types=1);

use App\Domain\Flows\Models\FlowConnection;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Services\FlowValidator;

/**
 * Crea un FlowNode en memoria (sin persistir): el validador opera sobre
 * modelos, no requiere base de datos.
 *
 * @param  array<string, mixed>  $config
 */
function make_validator_node(string $id, string $type, array $config = [], bool $isStart = false): FlowNode
{
    $node = new FlowNode([
        'type' => $type,
        'name' => 'Nodo '.$id,
        'config' => $config,
        'is_start' => $isStart,
    ]);

    $node->id = $id;

    return $node;
}

function make_validator_edge(string $from, string $to, ?string $label = null): FlowConnection
{
    return new FlowConnection([
        'source_node_id' => $from,
        'target_node_id' => $to,
        'label' => $label,
    ]);
}

/**
 * @param  array<string, mixed>  $questionConfig
 * @return list<string>
 */
function validate_question_field(array $questionConfig): array
{
    $nodes = [
        make_validator_node('n1', 'message', ['text' => 'Hola'], true),
        make_validator_node('n2', 'question', $questionConfig),
        make_validator_node('n3', 'end'),
    ];

    return app(FlowValidator::class)->validate($nodes, [
        make_validator_edge('n1', 'n2'),
        make_validator_edge('n2', 'n3'),
    ]);
}

test('VAR-7: un question con field snake_case válido pasa la validación', function (): void {
    expect(validate_question_field(['prompt' => '¿Nombre?', 'field' => 'nombre']))->toBe([])
        ->and(validate_question_field(['prompt' => '¿X?', 'field' => 'nombre_1']))->toBe([])
        ->and(validate_question_field(['prompt' => '¿X?', 'field' => 'nombre123']))->toBe([]);
});

test('VAR-7: type y default en la config del question no rompen la validación (FASE 13)', function (): void {
    $errors = validate_question_field([
        'prompt' => '¿Edad?',
        'field' => 'edad',
        'type' => 'integer',
        'default' => 0,
    ]);

    expect($errors)->toBe([]);
});

test('VAR-7: un question sin field es inválido', function (): void {
    expect(validate_question_field(['prompt' => '¿Nombre?']))->toContain(
        "El nodo \"Nodo n2\" (pregunta) requiere 'field' con nombre de variable válido.",
    );
});

test('VAR-7: rechaza field con mayúsculas (fix C8)', function (): void {
    foreach (['Nombre', 'NOMBRE', 'nombreApellido', 'Nombre_1'] as $field) {
        expect(validate_question_field(['prompt' => '¿X?', 'field' => $field]))->toContain(
            "El nodo \"Nodo n2\" (pregunta) requiere 'field' con nombre de variable válido.",
        );
    }
});

test('VAR-7: rechaza field peligrosos o fuera del patrón', function (): void {
    foreach (['__proto__', 'constructor', 'prototype', 'a__b', 'mal campo', 'nombre ', '_nombre', '1nombre', ''] as $field) {
        expect(validate_question_field(['prompt' => '¿X?', 'field' => $field]))->toContain(
            "El nodo \"Nodo n2\" (pregunta) requiere 'field' con nombre de variable válido.",
        );
    }
});
