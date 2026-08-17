<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowNodeType;
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

/**
 * @param  array<string, mixed>  $conditionConfig
 * @return list<string>
 */
function validate_condition_config(array $conditionConfig): array
{
    $nodes = [
        make_validator_node('n1', 'message', ['text' => 'Hola'], true),
        make_validator_node('n2', 'condition', $conditionConfig),
        make_validator_node('n3', 'message', ['text' => 'Sí']),
        make_validator_node('n4', 'message', ['text' => 'No']),
        make_validator_node('n5', 'end'),
    ];

    return app(FlowValidator::class)->validate($nodes, [
        make_validator_edge('n1', 'n2'),
        make_validator_edge('n2', 'n3', 'true'),
        make_validator_edge('n2', 'n4', 'false'),
        make_validator_edge('n3', 'n5'),
        make_validator_edge('n4', 'n5'),
    ]);
}

test('VAR-7: las reglas de condition aceptan starts_with/ends_with y match all/any', function (): void {
    foreach (['starts_with', 'ends_with'] as $operator) {
        expect(validate_condition_config(['rules' => [
            ['field' => 'custom.email', 'operator' => $operator, 'value' => 'ana'],
        ]]))->toBe([]);
    }

    expect(validate_condition_config(['match' => 'any', 'rules' => [
        ['field' => 'custom.plan', 'operator' => 'equals', 'value' => 'pro'],
        ['field' => 'custom.plan', 'operator' => 'equals', 'value' => 'admin'],
    ]]))->toBe([])
        ->and(validate_condition_config(['match' => 'all', 'rules' => [
            ['field' => 'custom.a', 'operator' => 'equals', 'value' => '1', 'not' => true],
        ]]))->toBe([]);
});

test('VAR-7: un condition con match no válido o not no booleano se rechaza', function (): void {
    $matchErrors = validate_condition_config(['match' => 'alguno', 'rules' => [
        ['field' => 'custom.a', 'operator' => 'equals', 'value' => '1'],
    ]]);

    expect($matchErrors)->toContain(
        "El nodo \"Nodo n2\" (condición) tiene un 'match' no válido (debe ser 'all' o 'any').",
    );

    $notErrors = validate_condition_config(['rules' => [
        ['field' => 'custom.a', 'operator' => 'equals', 'value' => '1', 'not' => 'si'],
    ]]);

    expect($notErrors)->toContain(
        "El nodo \"Nodo n2\" (condición) tiene una regla con 'not' no booleano.",
    );
});

test('UNIDAD 5: un question con type válido y default compatible pasa; default incompatible falla', function (): void {
    foreach (['string', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'array', 'object', 'null'] as $type) {
        expect(validate_question_field(['prompt' => '?', 'field' => 'x', 'type' => $type, 'default' => null]))->toBe([]);
    }

    expect(validate_question_field(['prompt' => '?', 'field' => 'x', 'type' => 'integer', 'default' => 5]))->toBe([])
        ->and(validate_question_field(['prompt' => '?', 'field' => 'x', 'type' => 'integer', 'default' => '7']))->toBe([])
        ->and(validate_question_field(['prompt' => '?', 'field' => 'x', 'type' => 'boolean', 'default' => 'sí']))->toBe([]);

    expect(validate_question_field(['prompt' => '?', 'field' => 'x', 'type' => 'integer', 'default' => 'abc']))->toContain(
        "El nodo \"Nodo n2\" (pregunta) tiene un 'default' incompatible con el tipo 'integer'.",
    );
});

test('UNIDAD 5: un question con type desconocido es inválido', function (): void {
    $errors = validate_question_field(['prompt' => '?', 'field' => 'x', 'type' => 'fecha']);

    expect($errors)->toContain(
        "El nodo \"Nodo n2\" (pregunta) tiene un 'type' no válido.",
    );
});

test('UNIDAD 5: textos que exceden la longitud máxima se rechazan', function (): void {
    $long = str_repeat('a', 4097);

    expect(validate_question_field(['prompt' => $long, 'field' => 'x']))->toContain(
        'El nodo "Nodo n2" (pregunta) excede la longitud máxima de texto.',
    );

    $nodes = [
        make_validator_node('n1', 'message', ['text' => $long], true),
        make_validator_node('n2', 'end'),
    ];

    $errors = app(FlowValidator::class)->validate($nodes, [make_validator_edge('n1', 'n2')]);

    expect($errors)->toContain('El nodo "Nodo n1" (mensaje) excede la longitud máxima de texto.');
});

test('UNIDAD 5: referencias con segmentos peligrosos son ERROR en textos', function (): void {
    foreach (['{{custom.__proto__}}', '{{custom.constructor}}', '{{custom.prototype}}', '{{custom.a..b}}'] as $token) {
        $errors = validate_question_field(['prompt' => '¿X '.$token.'?', 'field' => 'x']);

        expect($errors)->toContain(
            "El nodo \"Nodo n2\" (pregunta) contiene una referencia a variable inválida: \"{$token}\".",
        );
    }
});

test('UNIDAD 5: referencias válidas, node.* y namespaces desconocidos NO son errores', function (): void {
    expect(validate_question_field(['prompt' => 'Hola {{contact.name}} {{custom.x|default:\'inv\'}} {{node.id}} {{foo.bar}}', 'field' => 'x']))
        ->toBe([]);
});

test('UNIDAD 5: condition con field inválido (namespace desconocido o peligroso) se rechaza', function (): void {
    foreach (['foo.bar', 'custom.__proto__', 'custom.constructor', 'custom..x', 'custom'] as $field) {
        $errors = validate_condition_config(['rules' => [
            ['field' => $field, 'operator' => 'equals', 'value' => '1'],
        ]]);

        expect($errors)->toContain(
            "El nodo \"Nodo n2\" (condición) tiene una regla con 'field' de variable inválido: \"{$field}\".",
        );
    }
});

test('UNIDAD 5: condition acepta fields dotted válidos de todos los namespaces', function (): void {
    expect(validate_condition_config(['rules' => [
        ['field' => 'contact.name', 'operator' => 'equals', 'value' => 'Ana'],
        ['field' => 'business.email', 'operator' => 'exists'],
        ['field' => 'conversation.id', 'operator' => 'exists'],
        ['field' => 'contact.metadata', 'operator' => 'is_not_empty'],
    ]]))->toBe([]);
});

/**
 * @param  array<string, mixed>  $webhookConfig
 * @return list<string>
 */
function validate_webhook_config(array $webhookConfig): array
{
    $nodes = [
        make_validator_node('n1', 'message', ['text' => 'Hola'], true),
        make_validator_node('n2', 'webhook', $webhookConfig),
        make_validator_node('n3', 'end'),
    ];

    return app(FlowValidator::class)->validate($nodes, [
        make_validator_edge('n1', 'n2'),
        make_validator_edge('n2', 'n3'),
    ]);
}

test('UNIDAD 5: el webhook rechaza credenciales embebidas en el URL', function (): void {
    $errors = validate_webhook_config(['url' => 'https://user:secreto@example.com/hook', 'method' => 'POST']);

    expect($errors)->toContain(
        "El nodo \"Nodo n2\" (webhook) no puede incluir credenciales en la 'url'.",
    );
});

test('UNIDAD 5: el webhook rechaza interpolación de variables en el host (URL literal)', function (): void {
    $errors = validate_webhook_config(['url' => 'https://{{custom.host}}/hook', 'method' => 'POST']);

    expect($errors)->toContain(
        "El nodo \"Nodo n2\" (webhook) no puede interpolar variables en el host de la 'url'.",
    );

    // En el PATH el token es literal y se permite (contrato de FASE 11, VAR-17).
    expect(validate_webhook_config(['url' => 'https://example.com/hook/{{custom.plan}}', 'method' => 'POST']))->toBe([]);
});

test('UNIDAD 5: el webhook valida longitud y referencias peligrosas en headers/payload', function (): void {
    $errors = validate_webhook_config([
        'url' => 'https://example.com/hook',
        'method' => 'POST',
        'payload' => ['from' => '{{custom.__proto__}}', 'nested' => ['ok' => '{{contact.name}}']],
    ]);

    expect($errors)->toContain(
        'El nodo "Nodo n2" (webhook) contiene una referencia a variable inválida: "{{custom.__proto__}}".',
    );

    expect(validate_webhook_config([
        'url' => 'https://example.com/hook',
        'method' => 'POST',
        'headers' => ['X-User' => '{{contact.name}}'],
        'payload' => ['deep' => ['nested' => ['v' => '{{custom.plan|default:\'x\'}}']]],
    ]))->toBe([]);
});

test('HANDOFF-CONTRACT-01: human es terminal válido sin nodo end y acepta mensaje vacío o ausente', function (): void {
    foreach ([[], ['handoff_message' => ''], ['handoff_message' => '   '], ['handoff_message' => null]] as $config) {
        $nodes = [
            make_validator_node('n1', 'message', ['text' => 'Hola'], true),
            make_validator_node('n2', 'human', $config),
        ];

        expect(app(FlowValidator::class)->validate($nodes, [make_validator_edge('n1', 'n2')]))->toBe([]);
    }
});

test('HANDOFF-CONTRACT-02: human rechaza mensaje no-string o mayor a 4096 caracteres', function (): void {
    $validNodes = [
        make_validator_node('n1', 'message', ['text' => 'Hola'], true),
        make_validator_node('n2', 'human', ['handoff_message' => str_repeat('á', 4096)]),
    ];

    expect(app(FlowValidator::class)->validate($validNodes, [make_validator_edge('n1', 'n2')]))->toBe([]);

    foreach ([['handoff_message' => 123], ['handoff_message' => str_repeat('a', 4097)]] as $config) {
        $nodes = [
            make_validator_node('n1', 'message', ['text' => 'Hola'], true),
            make_validator_node('n2', 'human', $config),
        ];

        expect(app(FlowValidator::class)->validate($nodes, [make_validator_edge('n1', 'n2')]))->not->toBe([]);
    }
});

test('HANDOFF-CONTRACT-03: human sigue prohibiendo conexiones salientes', function (): void {
    $nodes = [
        make_validator_node('n1', 'message', ['text' => 'Hola'], true),
        make_validator_node('n2', 'human'),
        make_validator_node('n3', 'end'),
    ];

    expect(app(FlowValidator::class)->validate($nodes, [
        make_validator_edge('n1', 'n2'),
        make_validator_edge('n2', 'n3'),
    ]))->toContain('El nodo "Nodo n2" es terminal y no debe tener conexiones salientes.');
});

test('HANDOFF-CONTRACT-04: condition puede terminar en human en ambas ramas', function (): void {
    $nodes = [
        make_validator_node('n1', 'condition', [
            'rules' => [['field' => 'conversation.id', 'operator' => 'exists']],
        ], true),
        make_validator_node('n2', 'human'),
        make_validator_node('n3', 'human', ['handoff_message' => 'Te atenderemos pronto']),
    ];

    expect(app(FlowValidator::class)->validate($nodes, [
        make_validator_edge('n1', 'n2', 'true'),
        make_validator_edge('n1', 'n3', 'false'),
    ]))->toBe([]);
});

test('HANDOFF-CONTRACT-05: un grafo sin end ni human alcanzable sigue siendo inválido', function (): void {
    $node = make_validator_node('n1', 'message', ['text' => 'Hola'], true);

    expect(app(FlowValidator::class)->validate([$node], []))->toContain(
        'El flujo debe tener al menos un nodo terminal ("end" o "human") alcanzable desde el inicio.',
    );
});

test('HANDOFF-CONTRACT-06: human no es waiting y los tipos existentes conservan su contrato', function (): void {
    expect(FlowNodeType::Human->isWaitingType())->toBeFalse()
        ->and(FlowNodeType::Question->isWaitingType())->toBeTrue()
        ->and(FlowNodeType::Buttons->isWaitingType())->toBeTrue()
        ->and(FlowNodeType::AI->isWaitingType())->toBeTrue()
        ->and(FlowNodeType::End->isWaitingType())->toBeFalse();
});
