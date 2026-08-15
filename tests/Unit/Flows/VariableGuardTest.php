<?php

declare(strict_types=1);

use App\Domain\Flows\Services\VariableGuard;

test('VAR-4: acepta claves snake_case válidas', function (): void {
    expect(VariableGuard::isValidKey('nombre'))->toBeTrue()
        ->and(VariableGuard::isValidKey('nombre_1'))->toBeTrue()
        ->and(VariableGuard::isValidKey('nombre123'))->toBeTrue()
        ->and(VariableGuard::isValidKey('a'))->toBeTrue()
        ->and(VariableGuard::isValidKey(str_repeat('a', 64)))->toBeTrue();
});

test('VAR-4: rechaza mayúsculas (fix C8)', function (): void {
    expect(VariableGuard::isValidKey('Nombre'))->toBeFalse()
        ->and(VariableGuard::isValidKey('NOMBRE'))->toBeFalse()
        ->and(VariableGuard::isValidKey('nombreApellido'))->toBeFalse()
        ->and(VariableGuard::isValidKey('Nombre_1'))->toBeFalse();
});

test('VAR-4: rechaza claves peligrosas', function (): void {
    expect(VariableGuard::isValidKey('__proto__'))->toBeFalse()
        ->and(VariableGuard::isValidKey('constructor'))->toBeFalse()
        ->and(VariableGuard::isValidKey('prototype'))->toBeFalse()
        ->and(VariableGuard::isValidKey('a__b'))->toBeFalse();
});

test('VAR-4: rechaza claves vacías, con espacios o fuera del patrón', function (): void {
    expect(VariableGuard::isValidKey(''))->toBeFalse()
        ->and(VariableGuard::isValidKey('nombre '))->toBeFalse()
        ->and(VariableGuard::isValidKey(' nombre'))->toBeFalse()
        ->and(VariableGuard::isValidKey('mal campo'))->toBeFalse()
        ->and(VariableGuard::isValidKey('_nombre'))->toBeFalse()
        ->and(VariableGuard::isValidKey('1nombre'))->toBeFalse()
        ->and(VariableGuard::isValidKey('nombre-1'))->toBeFalse()
        ->and(VariableGuard::isValidKey('nombre.1'))->toBeFalse();
});

test('VAR-4: respeta el límite de longitud de la clave', function (): void {
    expect(VariableGuard::isValidKey(str_repeat('a', 64)))->toBeTrue()
        ->and(VariableGuard::isValidKey(str_repeat('a', 65)))->toBeFalse();
});

test('VAR-4: normaliza claves a minúsculas y sin espacios', function (): void {
    expect(VariableGuard::normalizeKey('Nombre'))->toBe('nombre')
        ->and(VariableGuard::normalizeKey('  Apellido  '))->toBe('apellido')
        ->and(VariableGuard::normalizeKey('NOMBRE_1'))->toBe('nombre_1')
        ->and(VariableGuard::normalizeKey('nombre'))->toBe('nombre');
});

test('VAR-4: solo permite escribir contacto vía metadata con clave válida', function (): void {
    expect(VariableGuard::isWritableContactField('metadata.clave'))->toBeTrue()
        ->and(VariableGuard::isWritableContactField('metadata.nombre_1'))->toBeTrue();

    expect(VariableGuard::isWritableContactField('name'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('email'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('phone'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('id'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('tenant_id'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('provider_contact_id'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('created_at'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('updated_at'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.__proto__'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.constructor'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.Name'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.phone'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.id'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.name'))->toBeFalse()
        ->and(VariableGuard::isWritableContactField('metadata.email'))->toBeFalse();
});

test('VAR-4: trunca valores a MAX_VALUE_LENGTH', function (): void {
    $value = str_repeat('x', VariableGuard::MAX_VALUE_LENGTH + 100);

    expect(mb_strlen(VariableGuard::truncateValue($value)))->toBe(VariableGuard::MAX_VALUE_LENGTH)
        ->and(VariableGuard::truncateValue('hola'))->toBe('hola');
});
