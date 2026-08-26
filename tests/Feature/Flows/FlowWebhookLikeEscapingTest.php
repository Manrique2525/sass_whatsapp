<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 26 U4 — LIKE Wildcard Escaping (P1-8)
|--------------------------------------------------------------------------
|
| Verifica que los caracteres especiales de LIKE (\, %, _) se escapan
| correctamente en FlowWebhookController::resolveByPhone() para prevenir
| bypass de búsqueda por expansión de wildcards.
|
*/

function like_escaping_setup(): array
{
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot, ['status' => FlowStatus::Published->value]);

    $nodeStart = 'ws-'.Str::random(8);
    $nodeEnd = 'we-'.Str::random(8);

    make_flow_graph($flow, [
        ['id' => $nodeStart, 'type' => 'message', 'name' => 'start', 'is_start' => true, 'config' => ['text' => 'Hola']],
        ['id' => $nodeEnd, 'type' => 'end', 'name' => 'end'],
    ], [
        ['from' => $nodeStart, 'to' => $nodeEnd],
    ]);

    $contact = make_contact($tenant, ['phone' => '+54112345678']);
    $conversation = make_conversation($tenant, $contact);

    ['trigger' => $trigger, 'token' => $token] = make_webhook_trigger($flow, 'phone');

    return compact('tenant', 'chatbot', 'flow', 'contact', 'conversation', 'trigger', 'token');
}

/*
|--------------------------------------------------------------------------
| LIKE-01: número normal resuelve conversación
|--------------------------------------------------------------------------
*/
test('LIKE-01: número normal resuelve conversación', function (): void {
    $s = like_escaping_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '54112345678',
    ])->assertStatus(202);
});

/*
|--------------------------------------------------------------------------
| LIKE-02: phone con % no expande wildcards
|--------------------------------------------------------------------------
*/
test('LIKE-02: phone con % no expande wildcards', function (): void {
    $s = like_escaping_setup();

    $response = post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '%',
    ]);

    $response->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| LIKE-03: phone con _ no matchea un solo carácter
|--------------------------------------------------------------------------
*/
test('LIKE-03: phone con _ no matchea un solo carácter', function (): void {
    $s = like_escaping_setup();

    $response = post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '_____',
    ]);

    $response->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| LIKE-04: phone con \% combinado no actúa como wildcard
|--------------------------------------------------------------------------
*/
test('LIKE-04: phone con \\% no actúa como wildcard', function (): void {
    $s = like_escaping_setup();

    $response = post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '%54112345678%',
    ]);

    $response->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| LIKE-05: phone con backslash se escapa correctamente
|--------------------------------------------------------------------------
*/
test('LIKE-05: phone con backslash se escapa correctamente', function (): void {
    $s = like_escaping_setup();

    $response = post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '\\54112345678',
    ]);

    $response->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| LIKE-06: phone vacío retorna 400
|--------------------------------------------------------------------------
*/
test('LIKE-06: phone vacío retorna 400', function (): void {
    $s = like_escaping_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '',
    ])->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| LIKE-07: phone con + normalizado se resuelve
|--------------------------------------------------------------------------
*/
test('LIKE-07: phone con + normalizado se resuelve', function (): void {
    $s = like_escaping_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '+54112345678',
    ])->assertStatus(202);
});

/*
|--------------------------------------------------------------------------
| LIKE-08: phone con espacios/guiones se normaliza y escapa
|--------------------------------------------------------------------------
*/
test('LIKE-08: phone con espacios/guiones se normaliza y escapa', function (): void {
    $s = like_escaping_setup();

    $contact = $s['contact'];
    $contact->update(['phone' => '+54 11-2345-678']);

    $response = post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => '54112345678',
    ]);

    $response->assertStatus(202);
});
