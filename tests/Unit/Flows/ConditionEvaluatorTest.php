<?php

declare(strict_types=1);

use App\Domain\Flows\Services\ConditionEvaluator;

/**
 * @param  array<string, mixed>  $variables
 */
function condition_evaluates(array $variables, array $rules, string $match = 'all'): bool
{
    return app(ConditionEvaluator::class)->evaluateGroup($variables, [
        'match' => $match,
        'rules' => $rules,
    ]);
}

test('VAR-3: equals compara strings, números coherentes y booleanos tipados', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.edad' => '10'], [['field' => 'custom.edad', 'operator' => 'equals', 'value' => 10]]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.edad' => 10], [['field' => 'custom.edad', 'operator' => 'equals', 'value' => '10']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.vip' => true], [['field' => 'custom.vip', 'operator' => 'equals', 'value' => 'si']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.vip' => 'sí'], [['field' => 'custom.vip', 'operator' => 'equals', 'value' => true]]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.vip' => '1'], [['field' => 'custom.vip', 'operator' => 'equals', 'value' => 'si']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.nombre' => 'Ana'], [['field' => 'custom.nombre', 'operator' => 'equals', 'value' => 'Ana']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.nombre' => 'Ana'], [['field' => 'custom.nombre', 'operator' => 'equals', 'value' => 'ana']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.edad' => '10'], [['field' => 'custom.edad', 'operator' => 'equals', 'value' => 11]]))->toBeFalse();
});

test('VAR-3: not_equals niega equals', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.edad' => '10'], [['field' => 'custom.edad', 'operator' => 'not_equals', 'value' => 11]]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.nombre' => 'Ana'], [['field' => 'custom.nombre', 'operator' => 'not_equals', 'value' => 'Ana']]))->toBeFalse();
});

test('VAR-3: contains y not_contains operan sobre strings', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.ciudad' => 'Buenos Aires'], [['field' => 'custom.ciudad', 'operator' => 'contains', 'value' => 'Aires']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.ciudad' => 'Buenos Aires'], [['field' => 'custom.ciudad', 'operator' => 'contains', 'value' => 'Córdoba']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.ciudad' => 'Buenos Aires'], [['field' => 'custom.ciudad', 'operator' => 'not_contains', 'value' => 'Córdoba']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.ciudad' => null], [['field' => 'custom.ciudad', 'operator' => 'contains', 'value' => 'Aires']]))->toBeFalse();
});

test('VAR-3: starts_with y ends_with (FASE 13)', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.email' => 'ana@test.com'], [['field' => 'custom.email', 'operator' => 'starts_with', 'value' => 'ana']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.email' => 'ana@test.com'], [['field' => 'custom.email', 'operator' => 'starts_with', 'value' => 'ana@']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.email' => 'ana@test.com'], [['field' => 'custom.email', 'operator' => 'starts_with', 'value' => '.com']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.email' => 'ana@test.com'], [['field' => 'custom.email', 'operator' => 'ends_with', 'value' => '.com']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.email' => 'ana@test.com'], [['field' => 'custom.email', 'operator' => 'ends_with', 'value' => 'ana']]))->toBeFalse();
});

test('VAR-3: greater/less/equal comparan números y fechas ISO', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.edad' => 30], [['field' => 'custom.edad', 'operator' => 'greater_than', 'value' => 18]]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.edad' => '30'], [['field' => 'custom.edad', 'operator' => 'greater_than', 'value' => '18']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.edad' => 30], [['field' => 'custom.edad', 'operator' => 'less_than', 'value' => 18]]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.edad' => 30], [['field' => 'custom.edad', 'operator' => 'greater_or_equal', 'value' => 30]]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.edad' => 30], [['field' => 'custom.edad', 'operator' => 'less_or_equal', 'value' => 30]]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.edad' => 'no-numérico'], [['field' => 'custom.edad', 'operator' => 'greater_than', 'value' => 1]]))->toBeFalse();

    expect($evaluator->evaluate(
        ['custom.fecha' => '2026-08-15'],
        [['field' => 'custom.fecha', 'operator' => 'greater_than', 'value' => '2026-08-14']],
    ))->toBeTrue()
        ->and($evaluator->evaluate(
            ['custom.fecha' => '2026-08-15'],
            [['field' => 'custom.fecha', 'operator' => 'less_than', 'value' => '2026-08-16']],
        ))->toBeTrue()
        ->and($evaluator->evaluate(
            ['custom.fecha' => '2026-08-15'],
            [['field' => 'custom.fecha', 'operator' => 'equals', 'value' => '2026-08-15']],
        ))->toBeTrue()
        // Fechas de solo día vs datetime: la igualdad es por instante exacto.
        ->and($evaluator->evaluate(
            ['custom.fecha' => '2026-08-15'],
            [['field' => 'custom.fecha', 'operator' => 'equals', 'value' => '2026-08-15T10:00:00+00:00']],
        ))->toBeFalse();
});

test('VAR-3: exists y not_exists dependen de la presencia de la clave', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.x' => null], [['field' => 'custom.x', 'operator' => 'exists']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.x' => null], [['field' => 'custom.x', 'operator' => 'not_exists']]))->toBeFalse()
        ->and($evaluator->evaluate([], [['field' => 'custom.x', 'operator' => 'exists']]))->toBeFalse()
        ->and($evaluator->evaluate([], [['field' => 'custom.x', 'operator' => 'not_exists']]))->toBeTrue();
});

test('VAR-3: is_empty e is_not_empty cubren null, cadena vacía, array vacío y false', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    foreach ([null, '', [], false] as $empty) {
        expect($evaluator->evaluate(['custom.x' => $empty], [['field' => 'custom.x', 'operator' => 'is_empty']]))->toBeTrue()
            ->and($evaluator->evaluate(['custom.x' => $empty], [['field' => 'custom.x', 'operator' => 'is_not_empty']]))->toBeFalse();
    }

    expect($evaluator->evaluate(['custom.x' => '0'], [['field' => 'custom.x', 'operator' => 'is_empty']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.x' => 0], [['field' => 'custom.x', 'operator' => 'is_empty']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.x' => 'texto'], [['field' => 'custom.x', 'operator' => 'is_not_empty']]))->toBeTrue();
});

test('VAR-3: match all exige todas las reglas; match any basta una', function (): void {
    $rules = [
        ['field' => 'custom.a', 'operator' => 'equals', 'value' => '1'],
        ['field' => 'custom.b', 'operator' => 'equals', 'value' => '2'],
    ];

    expect(condition_evaluates(['custom.a' => '1', 'custom.b' => '2'], $rules, 'all'))->toBeTrue()
        ->and(condition_evaluates(['custom.a' => '1', 'custom.b' => 'x'], $rules, 'all'))->toBeFalse()
        ->and(condition_evaluates(['custom.a' => '1', 'custom.b' => 'x'], $rules, 'any'))->toBeTrue()
        ->and(condition_evaluates(['custom.a' => 'x', 'custom.b' => 'y'], $rules, 'any'))->toBeFalse();
});

test('VAR-3: match por defecto es all', function (): void {
    $evaluator = app(ConditionEvaluator::class);
    $rules = [
        ['field' => 'custom.a', 'operator' => 'equals', 'value' => '1'],
        ['field' => 'custom.b', 'operator' => 'equals', 'value' => '2'],
    ];

    expect($evaluator->evaluateGroup(['custom.a' => '1', 'custom.b' => 'x'], ['rules' => $rules]))->toBeFalse()
        ->and($evaluator->evaluateGroup(['custom.a' => '1', 'custom.b' => '2'], ['rules' => $rules]))->toBeTrue();
});

test('VAR-3: not por regla niega el resultado de esa regla', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.plan' => 'pro'], [
        ['field' => 'custom.plan', 'operator' => 'equals', 'value' => 'gratis', 'not' => true],
    ]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.plan' => 'pro'], [
            ['field' => 'custom.plan', 'operator' => 'equals', 'value' => 'pro', 'not' => true],
        ]))->toBeFalse();
});

test('VAR-3: reglas vacías o mal formadas evalúan a false sin excepciones', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.x' => '1'], []))->toBeFalse()
        ->and($evaluator->evaluateGroup(['custom.x' => '1'], []))->toBeFalse()
        ->and($evaluator->evaluate(['custom.x' => '1'], [['field' => 'custom.x', 'operator' => 'operador_inexistente', 'value' => '1']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.x' => '1'], [['field' => '', 'operator' => 'equals', 'value' => '1']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.x' => '1'], [['field' => 'custom.x']]))->toBeFalse();
});

test('VAR-3: la comparación booleana respeta exactamente las reglas de VariableType', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.si' => '1'], [['field' => 'custom.si', 'operator' => 'equals', 'value' => 'sí']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.no' => '0'], [['field' => 'custom.no', 'operator' => 'equals', 'value' => 'no']]))->toBeTrue()
        ->and($evaluator->evaluate(['custom.no' => '0'], [['field' => 'custom.no', 'operator' => 'equals', 'value' => 'true']]))->toBeFalse();
});

test('VAR-3: null no es igual a nada salvo a null', function (): void {
    $evaluator = app(ConditionEvaluator::class);

    expect($evaluator->evaluate(['custom.x' => null], [['field' => 'custom.x', 'operator' => 'equals', 'value' => '']]))->toBeFalse()
        ->and($evaluator->evaluate(['custom.x' => null], [['field' => 'custom.x', 'operator' => 'equals', 'value' => null]]))->toBeTrue();
});
