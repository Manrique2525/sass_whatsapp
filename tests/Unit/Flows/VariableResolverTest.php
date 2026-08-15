<?php

declare(strict_types=1);

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Services\VariableGuard;
use App\Domain\Flows\Services\VariableResolver;

/**
 * Contexto de resolución mínimo (sin base de datos): modelos Eloquent con
 * atributos en memoria, tal como los usa el motor en producción.
 *
 * @param  array<string, mixed>  $custom
 * @param  array<string, mixed>  $contactAttributes
 * @param  array<string, mixed>  $businessAttributes
 */
function variable_resolver_context(
    array $custom = [],
    array $contactAttributes = [],
    array $businessAttributes = [],
    ?int $conversationId = 42,
): array {
    $contact = new Contact(array_merge([
        'name' => 'Ana',
        'phone' => '+541100000000',
        'email' => 'ana@test.com',
        'metadata' => ['ciudad' => 'Buenos Aires', 'plan' => 'pro'],
    ], $contactAttributes));

    $business = new BusinessProfile(array_merge([
        'name' => 'Mi Negocio',
        'description' => 'Descripción',
        'website' => 'https://m.negocio.com',
    ], $businessAttributes));

    $conversation = (new Conversation)->forceFill(['id' => $conversationId]);

    return [$contact, $business, $conversation, $custom];
}

/**
 * Resuelve el texto con el contexto dado.
 *
 * @param  array<string, mixed>  $custom
 * @param  array<string, mixed>  $contactAttributes
 * @param  array<string, mixed>  $businessAttributes
 */
function variable_resolve_text(
    string $text,
    array $custom = [],
    array $contactAttributes = [],
    array $businessAttributes = [],
    ?int $conversationId = 42,
): string {
    [$contact, $business, $conversation] = variable_resolver_context($custom, $contactAttributes, $businessAttributes, $conversationId);

    return app(VariableResolver::class)->resolve($text, $contact, $business, $conversation, $custom);
}

test('VAR-1: resuelve contact, business (whitelist), conversation y custom', function (): void {
    $text = variable_resolve_text(
        'Hola {{contact.name}} ({{contact.email}}). {{business.name}} - {{business.website}}. #{{conversation.id}}. Ciudad: {{custom.ciudad}}',
        ['ciudad' => 'Córdoba'],
    );

    expect($text)->toBe(
        'Hola Ana (ana@test.com). Mi Negocio - https://m.negocio.com. #42. Ciudad: Córdoba',
    );
});

test('VAR-1: keys en mayúsculas se normalizan a minúsculas antes de resolver', function (): void {
    $text = variable_resolve_text('{{Contact.Name}} {{CUSTOM.ciudad}}', ['ciudad' => 'Córdoba']);

    expect($text)->toBe('Ana Córdoba');
});

test('VAR-1: namespace conocido con propiedad inexistente resuelve a vacío', function (): void {
    $text = variable_resolve_text('A[{{contact.inexistente}}]B[{{custom.noexiste}}]');

    expect($text)->toBe('A[]B[]');
});

test('VAR-1: business solo expone la whitelist PUBLIC_FIELDS (sin secretos)', function (): void {
    $text = variable_resolve_text(
        '{{business.access_token}}|{{business.name}}|{{business.tenant_id}}|{{business.email}}',
        businessAttributes: ['email' => 'hola@negocio.com'],
    );

    expect($text)->toBe('|Mi Negocio||hola@negocio.com');
});

test('VAR-1: namespace desconocido o node.* se conserva verbatim', function (): void {
    $text = variable_resolve_text('{{node.id}} {{foo.bar}} {{noexiste}}');

    expect($text)->toBe('{{node.id}} {{foo.bar}} {{noexiste}}');
});

test('VAR-1: conversación solo expone conversation.id', function (): void {
    expect(variable_resolve_text('{{conversation.status}}'))->toBe('')
        ->and(variable_resolve_text('{{conversation.contact_id}}'))->toBe('');
});

test('VAR-1: múltiples variables en una sola línea se sustituyen todas', function (): void {
    $text = variable_resolve_text('{{contact.name}}|{{business.name}}|{{custom.a}}|{{custom.b}}|{{conversation.id}}', [
        'a' => '1',
        'b' => '2',
    ]);

    expect($text)->toBe('Ana|Mi Negocio|1|2|42');
});

test('VAR-1: se eliminan los caracteres de control del resultado', function (): void {
    $text = variable_resolve_text("Hola {{custom.x}}\u{0007}", ['x' => "valor\u{0008}"]);

    expect($text)->toBe('Hola valor');
});

test('VAR-1: no se permite el acceso a rutas arbitrarias (path traversal)', function (): void {
    $text = variable_resolve_text('{{contact.metadata.ciudad}} {{contact.metadata}}');

    expect($text)->toBe(' ');
});

test('VAR-1 (UNIDAD 5): metadata.* se expone como contact.<campo>; no como ruta', function (): void {
    // Interpretación de `{{contact.metadata.*}}` del contrato: los campos de
    // metadata se resuelven con `{{contact.<campo>}}` (UNIDAD 2). El acceso por
    // ruta `contact.metadata.<clave>` sigue bloqueado (test anterior).
    expect(variable_resolve_text('Ciudad: {{contact.ciudad}}, plan {{contact.plan}}'))
        ->toBe('Ciudad: Buenos Aires, plan pro');
});

test('VAR-16: el default se usa cuando la variable no existe', function (): void {
    $text = variable_resolve_text('Hola {{custom.nombre|default:\'invitado\'}}');

    expect($text)->toBe('Hola invitado');
});

test('VAR-16: el default se usa cuando el valor es null o vacío', function (): void {
    expect(variable_resolve_text('x{{custom.a|default:\'D\'}}', ['a' => null]))->toBe('xD')
        ->and(variable_resolve_text('x{{custom.b|default:\'D\'}}', ['b' => '']))->toBe('xD')
        ->and(variable_resolve_text('x{{custom.c|default:\'D\'}}', ['c' => []]))->toBe('xD')
        ->and(variable_resolve_text('x{{custom.d|default:\'D\'}}', ['d' => false]))->toBe('xfalse')
        ->and(variable_resolve_text('x{{custom.e|default:\'D\'}}', ['e' => 0]))->toBe('x0');
});

test('VAR-16: un valor existente no se reemplaza por el default', function (): void {
    $text = variable_resolve_text('{{custom.plan|default:\'gratis\'}}', ['plan' => 'pro']);

    expect($text)->toBe('pro');
});

test('VAR-16: el default admite caracteres especiales y escapes', function (): void {
    $text = variable_resolve_text("{{custom.x|default:'una\\' dos\\'tres \\\\final'}}");

    expect($text)->toBe("una' dos'tres \\final");
});

test('VAR-16: se admiten espacios alrededor de la clave y del filtro', function (): void {
    $text = variable_resolve_text("{{ custom.edad | default: '18' }}");

    expect($text)->toBe('18');
});

test('VAR-16: el default con namespace desconocido se conserva verbatim con su filtro', function (): void {
    $text = variable_resolve_text("{{node.id|default:'x'}}");

    expect($text)->toBe("{{node.id|default:'x'}}");
});

test('VAR-22: no hay template injection (el texto de entrada no ejecuta nada)', function (): void {
    $text = variable_resolve_text('{{contact.name}}{{system.id}}<?php echo "x"; ?>');

    expect($text)->toBe('Ana{{system.id}}<?php echo "x"; ?>');
});

test('VAR-23: claves peligrosas (__proto__, constructor, prototype) nunca se resuelven desde custom', function (): void {
    $text = variable_resolve_text('{{custom.__proto__}}|{{custom.constructor}}|{{custom.prototype}}', [
        '__proto__' => 'peligro',
        'constructor' => 'peligro',
        'prototype' => 'peligro',
    ]);

    expect($text)->toBe('||');
});

test('representación tipada: boolean, número y null se serializan de forma estable', function (): void {
    $text = variable_resolve_text('{{custom.si}}|{{custom.no}}|{{custom.num}}|{{custom.dec}}|{{custom.nulo}}', [
        'si' => true,
        'no' => false,
        'num' => 10,
        'dec' => 10.5,
        'nulo' => null,
    ]);

    expect($text)->toBe('true|false|10|10.5|');
});

test('representación tipada: date y datetime usan Y-m-d e ISO 8601', function (): void {
    $text = variable_resolve_text('{{custom.fecha}}|{{custom.fecha_hora}}', [
        'fecha' => '2026-08-15',
        'fecha_hora' => '2026-08-15T10:30:00+00:00',
    ]);

    expect($text)->toBe('2026-08-15|2026-08-15T10:30:00+00:00');
});

test('representación tipada: arrays y objetos usan JSON determinístico', function (): void {
    $text = variable_resolve_text('{{custom.arr}}|{{custom.obj}}', [
        'arr' => ['a' => 1, 'b' => 2],
        'obj' => (object) ['x' => true],
    ]);

    expect($text)->toBe('{"a":1,"b":2}|{"x":true}');
});

test('extractReferences: devuelve referencias base sin duplicados y en orden estable', function (): void {
    $references = app(VariableResolver::class)->extractReferences(
        'Hola {{contact.name}} {{contact.name}} {{custom.edad}} {{custom.edad|default:\'18\'}}',
    );

    expect($references)->toBe(['contact.name', 'custom.edad']);
});

test('extractReferences: ignora namespaces no válidos, node.* y claves multi-segmento', function (): void {
    $references = app(VariableResolver::class)->extractReferences(
        '{{node.id}} {{foo.bar}} {{custom.mal campo}} {{custom.a.b}} {{contact.metadata.ciudad}} {{custom.ok}}',
    );

    expect($references)->toBe(['custom.ok']);
});

test('extractReferences: no resuelve valores (solo extrae)', function (): void {
    $resolver = app(VariableResolver::class);

    expect($resolver->extractReferences('{{custom.nombre}}'))->toBe(['custom.nombre']);

    $references = $resolver->extractReferences('texto sin variables');
    expect($references)->toBe([]);
});

test('keys fuera del patrón se conservan tal cual (sin crash)', function (): void {
    $text = variable_resolve_text('{{ }} {{contact}} {{}}');

    expect($text)->toBe('{{ }}  {{}}');
});

test('VariableGuard sigue siendo la única autoridad de claves custom', function (): void {
    expect(VariableGuard::isValidKey('ciudad'))->toBeTrue()
        ->and(VariableGuard::isValidKey('__proto__'))->toBeFalse();
});
