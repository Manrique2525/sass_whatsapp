<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\VariableType;
use App\Domain\Flows\ValueObjects\VariableCoercion;

test('VAR-2: string acepta cualquier valor y serializa estructuras', function (): void {
    expect(VariableType::String->coerce('hola')->ok)->toBeTrue()
        ->and(VariableType::String->coerce('hola')->value)->toBe('hola')
        ->and(VariableType::String->coerce(42)->value)->toBe('42')
        ->and(VariableType::String->coerce(1.5)->value)->toBe('1.5')
        ->and(VariableType::String->coerce(true)->value)->toBe('1')
        ->and(VariableType::String->coerce(null)->value)->toBe('')
        ->and(VariableType::String->coerce(['a', 'b'])->value)->toBe('["a","b"]');
});

test('VAR-2: integer acepta enteros y strings enteras', function (): void {
    $ok = fn (mixed $value): int|string => VariableType::Integer->coerce($value)->value;

    expect($ok(5))->toBe(5)
        ->and($ok('42'))->toBe(42)
        ->and($ok(3.0))->toBe(3);
});

test('VAR-2: integer rechaza no enteros sin lanzar excepciones', function (): void {
    expect(VariableType::Integer->coerce('abc')->ok)->toBeFalse()
        ->and(VariableType::Integer->coerce(1.5)->ok)->toBeFalse()
        ->and(VariableType::Integer->coerce('1.5')->ok)->toBeFalse()
        ->and(VariableType::Integer->coerce([])->ok)->toBeFalse()
        ->and(VariableType::Integer->coerce(true)->ok)->toBeFalse()
        ->and(VariableType::Integer->coerce('  42  ')->ok)->toBeTrue();
});

test('VAR-2: decimal acepta cualquier valor numérico', function (): void {
    expect(VariableType::Decimal->coerce('1.75')->value)->toBe(1.75)
        ->and(VariableType::Decimal->coerce(5)->value)->toBe(5.0)
        ->and(VariableType::Decimal->coerce('abc')->ok)->toBeFalse()
        ->and(VariableType::Decimal->coerce([])->ok)->toBeFalse();
});

test('VAR-2: boolean mapea true/false, 1/0 y sí/no', function (): void {
    expect(VariableType::Boolean->coerce(true)->value)->toBeTrue()
        ->and(VariableType::Boolean->coerce('true')->value)->toBeTrue()
        ->and(VariableType::Boolean->coerce('1')->value)->toBeTrue()
        ->and(VariableType::Boolean->coerce('sí')->value)->toBeTrue()
        ->and(VariableType::Boolean->coerce('SI')->value)->toBeTrue()
        ->and(VariableType::Boolean->coerce('si')->value)->toBeTrue()
        ->and(VariableType::Boolean->coerce('yes')->value)->toBeTrue()
        ->and(VariableType::Boolean->coerce(false)->value)->toBeFalse()
        ->and(VariableType::Boolean->coerce('0')->value)->toBeFalse()
        ->and(VariableType::Boolean->coerce('no')->value)->toBeFalse()
        ->and(VariableType::Boolean->coerce('NO')->value)->toBeFalse();

    expect(VariableType::Boolean->coerce('quizas')->ok)->toBeFalse()
        ->and(VariableType::Boolean->coerce([])->ok)->toBeFalse();
});

test('VAR-2: date normaliza a Y-m-d y datetime a ISO 8601', function (): void {
    expect(VariableType::Date->coerce('2025-06-01')->value)->toBe('2025-06-01')
        ->and(VariableType::Date->coerce('2025-06-01T10:30:00Z')->value)->toBe('2025-06-01')
        ->and(VariableType::DateTime->coerce('2025-06-01T10:30:00Z')->value)->toBe('2025-06-01T10:30:00+00:00');

    expect(VariableType::Date->coerce('no es fecha')->ok)->toBeFalse()
        ->and(VariableType::Date->coerce('')->ok)->toBeFalse()
        ->and(VariableType::Date->coerce(null)->ok)->toBeFalse()
        ->and(VariableType::DateTime->coerce('no es fecha')->ok)->toBeFalse();
});

test('VAR-2: array acepta arrays y strings JSON de array', function (): void {
    $fromJson = VariableType::Array->coerce('["a","b"]');
    $fromObjectJson = VariableType::Array->coerce('{"a":1}');

    expect(VariableType::Array->coerce(['a', 'b'])->value)->toBe(['a', 'b'])
        ->and($fromJson->ok)->toBeTrue()
        ->and($fromJson->value)->toBe(['a', 'b'])
        ->and($fromObjectJson->ok)->toBeTrue()
        ->and($fromObjectJson->value)->toBe(['a' => 1]);

    expect(VariableType::Array->coerce('nope')->ok)->toBeFalse()
        ->and(VariableType::Array->coerce(42)->ok)->toBeFalse()
        ->and(VariableType::Array->coerce(null)->ok)->toBeFalse();
});

test('VAR-2: object acepta objetos, arrays asociativos y JSON de objeto', function (): void {
    $fromArray = VariableType::Object->coerce(['a' => 1]);

    expect($fromArray->ok)->toBeTrue()
        ->and($fromArray->value)->toBeInstanceOf(stdClass::class)
        ->and($fromArray->value->a)->toBe(1);

    expect(VariableType::Object->coerce('{"a":1}')->ok)->toBeTrue()
        ->and(VariableType::Object->coerce('nope')->ok)->toBeFalse()
        ->and(VariableType::Object->coerce('["a"]')->ok)->toBeFalse();
});

test('VAR-2: null solo acepta null o cadena vacía', function (): void {
    expect(VariableType::Null->coerce(null)->ok)->toBeTrue()
        ->and(VariableType::Null->coerce(null)->value)->toBeNull()
        ->and(VariableType::Null->coerce('')->ok)->toBeTrue();

    expect(VariableType::Null->coerce('x')->ok)->toBeFalse()
        ->and(VariableType::Null->coerce(0)->ok)->toBeFalse()
        ->and(VariableType::Null->coerce(false)->ok)->toBeFalse();
});

test('VAR-2: la coerción nunca lanza excepciones', function (): void {
    $inputs = [null, true, false, 0, 1, -1, 1.5, 'x', '', [], ['a'], new stdClass];

    foreach (VariableType::cases() as $type) {
        foreach ($inputs as $input) {
            $result = $type->coerce($input);

            expect($result)->toBeInstanceOf(VariableCoercion::class)
                ->and($result->ok)->toBeBool();
        }
    }
});
