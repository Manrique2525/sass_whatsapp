<?php

declare(strict_types=1);

namespace Tests\Unit\WhatsApp;

use App\Domain\WhatsApp\Exceptions\WhatsAppTemplateValidationException;
use App\Domain\WhatsApp\Services\TemplateVariableValidator;

/**
 * Valida variables de envío contra el schema de componentes (FASE 31 U5).
 */
test('VARS-1: valida cardinalidad exacta y normaliza params', function (): void {
    $validator = new TemplateVariableValidator;

    $params = $validator->validate([
        ['type' => 'BODY', 'text' => 'Hola {{1}}, tu pedido {{2}} está listo.'],
    ], ['Juan', 'ABC-123']);

    expect($params)->toBe([
        ['type' => 'text', 'text' => 'Juan'],
        ['type' => 'text', 'text' => 'ABC-123'],
    ]);
});

test('VARS-2: faltan variables → rechazo sin params', function (): void {
    $validator = new TemplateVariableValidator;

    expect(fn (): mixed => $validator->validate(
        [['type' => 'BODY', 'text' => 'Hola {{1}} {{2}}']],
        ['Juan'],
    ))->toThrow(WhatsAppTemplateValidationException::class);
});

test('VARS-3: sobran variables → rechazo', function (): void {
    $validator = new TemplateVariableValidator;

    expect(fn (): mixed => $validator->validate(
        [['type' => 'BODY', 'text' => 'Hola {{1}}']],
        ['Juan', 'extra'],
    ))->toThrow(WhatsAppTemplateValidationException::class);
});

test('VARS-4: template sin placeholders rechaza cualquier variable', function (): void {
    $validator = new TemplateVariableValidator;

    expect(fn (): mixed => $validator->validate(
        [['type' => 'BODY', 'text' => 'Sin variables.']],
        ['Juan'],
    ))->toThrow(WhatsAppTemplateValidationException::class);

    expect($validator->validate([['type' => 'BODY', 'text' => 'Sin variables.']], []))->toBe([]);
});

test('VARS-5: variable no escalar → rechazo por malformed', function (): void {
    $validator = new TemplateVariableValidator;

    expect(fn (): mixed => $validator->validate(
        [['type' => 'BODY', 'text' => 'Hola {{1}}']],
        [['nested']],
    ))->toThrow(WhatsAppTemplateValidationException::class);
});

test('VARS-6: placeholders solo en BODY/HEADER; FOOTER ignorado', function (): void {
    $validator = new TemplateVariableValidator;

    $params = $validator->validate([
        ['type' => 'BODY', 'text' => 'Hola {{1}}'],
        ['type' => 'FOOTER', 'text' => 'Respuesta {{99}}'],
    ], ['Ana']);

    expect($params)->toBe([['type' => 'text', 'text' => 'Ana']]);
});
