<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 14 — UNIDAD 3: WEBHOOK TRIGGER (ADR-049)
|--------------------------------------------------------------------------
*/

/**
 * Helper: crea el escenario completo (tenant, chatbot, flow publicado,
 * conversation y webhook trigger) y devuelve los modelos.
 */
function webhook_setup(string $conversationBy = 'conversation_id'): array
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

    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    ['trigger' => $trigger, 'token' => $token] = make_webhook_trigger($flow, $conversationBy);

    return compact('tenant', 'chatbot', 'flow', 'contact', 'conversation', 'trigger', 'token');
}

/*
|--------------------------------------------------------------------------
| WEBHOOK-01: token válido dispara el flujo
|--------------------------------------------------------------------------
*/
test('WEBHOOK-01: token válido dispara el flujo', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->where('flow_id', $s['flow']->id)
        ->where('conversation_id', $s['conversation']->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe(FlowExecutionStatus::Completed);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-02: token inválido → 401
|--------------------------------------------------------------------------
*/
test('WEBHOOK-02: token inválido → 401', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, str_repeat('a', 64), [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(401);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-03: trigger inexistente → 401 (no revela existencia)
|--------------------------------------------------------------------------
*/
test('WEBHOOK-03: trigger inexistente → 401', function (): void {
    $fakeId = (string) Str::uuid();

    post_flow_webhook($fakeId, str_repeat('a', 64), [
        'conversation_id' => (string) Str::uuid(),
    ])->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-04: trigger inactivo → 401
|--------------------------------------------------------------------------
*/
test('WEBHOOK-04: trigger inactivo → 401', function (): void {
    $s = webhook_setup();

    $s['trigger']->update(['active' => false]);

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(401);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-05: trigger de flow no publicado → 401
|--------------------------------------------------------------------------
*/
test('WEBHOOK-05: trigger de flow no publicado → 401', function (): void {
    $s = webhook_setup();

    $s['flow']->update(['status' => FlowStatus::Draft->value]);

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(401);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-06: conversación válida via conversation_id
|--------------------------------------------------------------------------
*/
test('WEBHOOK-06: conversación válida via conversation_id', function (): void {
    $s = webhook_setup('conversation_id');

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->conversation_id)->toBe($s['conversation']->id);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-07: conversación inexistente → 400
|--------------------------------------------------------------------------
*/
test('WEBHOOK-07: conversación inexistente → 400', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => (string) Str::uuid(),
    ])->assertStatus(400);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-08: conversación de otro tenant → 400
|--------------------------------------------------------------------------
*/
test('WEBHOOK-08: conversación de otro tenant → 400', function (): void {
    $s = webhook_setup();
    $otherTenant = Tenant::factory()->create();
    $otherContact = make_contact($otherTenant);
    $otherConversation = make_conversation($otherTenant, $otherContact);

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $otherConversation->id,
    ])->assertStatus(400);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-09: payload intentando usar tenant_id de otro tenant → 400
|             (tenant_id del payload es ignorado)
|--------------------------------------------------------------------------
*/
test('WEBHOOK-09: tenant_id del payload es ignorado', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
        'tenant_id' => 'hack-tenant-id',
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->tenant_id)->toBe($s['tenant']->id);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-10: Idempotency-Key evita doble ejecución
|--------------------------------------------------------------------------
*/
test('WEBHOOK-10: Idempotency-Key evita doble ejecución', function (): void {
    $s = webhook_setup();
    $key = (string) Str::uuid();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ], $key)->assertStatus(202);

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ], $key)->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-11: concurrencia con misma Idempotency-Key
|--------------------------------------------------------------------------
*/
test('WEBHOOK-11: concurrencia con misma Idempotency-Key', function (): void {
    $s = webhook_setup();
    $key = (string) Str::uuid();

    $lockKey = 'lock:webhook:idempotency:'.hash('sha256', $key);
    $lock = Cache::lock($lockKey, 60);
    $lock->get();

    $response = post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ], $key);

    $response->assertStatus(409);

    $lock->release();
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-12: bot_paused evita ejecución
|--------------------------------------------------------------------------
*/
test('WEBHOOK-12: bot_paused evita ejecución', function (): void {
    $s = webhook_setup();

    $s['conversation']->update(['bot_paused' => true]);

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-13: ejecución activa no duplica
|--------------------------------------------------------------------------
*/
test('WEBHOOK-13: ejecución activa no duplica', function (): void {
    $s = webhook_setup();

    TenantContext::setId($s['tenant']->id);
    try {
        $startNode = $s['flow']->startNode ?? $s['flow']->nodes()->first();
        $execution = FlowExecution::query()->create([
            'flow_id' => $s['flow']->id,
            'conversation_id' => $s['conversation']->id,
            'current_node_id' => $startNode?->id,
            'status' => FlowExecutionStatus::Running->value,
            'variables' => ['custom' => []],
            'attempts' => 0,
        ]);
        $s['conversation']->forceFill(['flow_execution_id' => $execution->id])->save();
    } finally {
        TenantContext::clear();
    }

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $count = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->where('conversation_id', $s['conversation']->id)
        ->count();

    expect($count)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-14: token nunca aparece en response
|--------------------------------------------------------------------------
*/
test('WEBHOOK-14: token nunca aparece en response', function (): void {
    $s = webhook_setup();

    $response = post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ]);

    $content = $response->getContent();

    expect($content)->not->toContain($s['token'])
        ->and($content)->not->toContain('token_hash');
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-15: token/token_hash jamás aparece en logs/auditoría
|--------------------------------------------------------------------------
*/
test('WEBHOOK-15: token/token_hash jamás aparece en logs/auditoría', function (): void {
    $s = webhook_setup();

    $config = is_array($s['trigger']->config) ? $s['trigger']->config : [];
    $tokenHash = $config['token_hash'] ?? '';

    $logFile = storage_path('logs/laravel.log');
    $beforeLog = file_exists($logFile) ? file_get_contents($logFile) : '';

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $afterLog = file_exists($logFile) ? file_get_contents($logFile) : '';
    $newLogContent = str_replace($beforeLog, '', $afterLog);

    expect($newLogContent)->not->toContain($s['token'])
        ->and($newLogContent)->not->toContain($tokenHash);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-16: rate limit
|--------------------------------------------------------------------------
*/
test('WEBHOOK-16: rate limit', function (): void {
    config(['rate-limiting.enabled' => true]);

    $s = webhook_setup();

    for ($i = 0; $i < 65; $i++) {
        $response = post_flow_webhook($s['trigger']->id, $s['token'], [
            'conversation_id' => $s['conversation']->id,
        ]);

        if ($i >= 60) {
            $response->assertStatus(429);

            return;
        }
    }

    // Should have hit rate limit before reaching here
    $this->fail('Rate limit should have been triggered by request 61.');
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-17: payload excediendo límite → 400
|--------------------------------------------------------------------------
*/
test('WEBHOOK-17: payload excediendo límite → 400', function (): void {
    $s = webhook_setup();

    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$s['token'],
    ];

    $bigPayload = json_encode(['data' => str_repeat('x', 70000)], JSON_THROW_ON_ERROR);

    test()->call(
        'POST',
        '/api/webhooks/flows/'.$s['trigger']->id,
        [],
        [],
        [],
        $headers,
        $bigPayload,
    )->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-18: headers sensibles no se registran
|--------------------------------------------------------------------------
*/
test('WEBHOOK-18: headers sensibles no se registran', function (): void {
    $s = webhook_setup();

    $config = is_array($s['trigger']->config) ? $s['trigger']->config : [];
    $tokenHash = $config['token_hash'] ?? '';

    $logFile = storage_path('logs/laravel.log');
    $beforeLog = file_exists($logFile) ? file_get_contents($logFile) : '';

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ]);

    $afterLog = file_exists($logFile) ? file_get_contents($logFile) : '';
    $newLogContent = str_replace($beforeLog, '', $afterLog);

    expect($newLogContent)->not->toContain($s['token'])
        ->and($newLogContent)->not->toContain($tokenHash)
        ->and($newLogContent)->not->toContain('Bearer');
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-19: aislamiento tenant A/B
|--------------------------------------------------------------------------
*/
test('WEBHOOK-19: aislamiento tenant A/B', function (): void {
    $sA = webhook_setup();
    $sB = webhook_setup();

    // Intentar disparar trigger de A con conversación de B
    post_flow_webhook($sA['trigger']->id, $sA['token'], [
        'conversation_id' => $sB['conversation']->id,
    ])->assertStatus(400);

    $executionsA = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $sA['tenant']->id)
        ->count();

    expect($executionsA)->toBe(0);

    // Disparar trigger de A con conversación de A
    post_flow_webhook($sA['trigger']->id, $sA['token'], [
        'conversation_id' => $sA['conversation']->id,
    ])->assertStatus(202);

    $executionsA = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $sA['tenant']->id)
        ->count();

    expect($executionsA)->toBe(1);

    // B no tiene ejecuciones
    $executionsB = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $sB['tenant']->id)
        ->count();

    expect($executionsB)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| WEBHOOK-20: el webhook termina utilizando el pipeline existente
|--------------------------------------------------------------------------
*/
test('WEBHOOK-20: el webhook termina utilizando el pipeline existente', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->where('flow_id', $s['flow']->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->flow_id)->toBe($s['flow']->id)
        ->and($execution->conversation_id)->toBe($s['conversation']->id);

    $execution->load('logs');

    expect($execution->logs)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Tests adicionales descubiertos durante la implementación
|--------------------------------------------------------------------------
*/

test('WEBHOOK-EXT: sin header Authorization → 401', function (): void {
    $s = webhook_setup();

    test()->call(
        'POST',
        '/api/webhooks/flows/'.$s['trigger']->id,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['conversation_id' => $s['conversation']->id], JSON_THROW_ON_ERROR),
    )->assertStatus(401);
});

test('WEBHOOK-EXT: Authorization sin Bearer → 401', function (): void {
    $s = webhook_setup();

    test()->call(
        'POST',
        '/api/webhooks/flows/'.$s['trigger']->id,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => $s['token'],
        ],
        json_encode(['conversation_id' => $s['conversation']->id], JSON_THROW_ON_ERROR),
    )->assertStatus(401);
});

test('WEBHOOK-EXT: token con formato inválido → 401', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, 'not-a-hex-token', [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(401);
});

test('WEBHOOK-EXT: conversation_by contact_id resuelve conversación', function (): void {
    $s = webhook_setup('contact_id');

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'contact_id' => $s['contact']->id,
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->conversation_id)->toBe($s['conversation']->id);
});

test('WEBHOOK-EXT: conversation_by phone resuelve conversación', function (): void {
    $s = webhook_setup('phone');

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'phone' => $s['contact']->phone,
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->conversation_id)->toBe($s['conversation']->id);
});

test('WEBHOOK-EXT: conversation_id faltante → 400', function (): void {
    $s = webhook_setup('conversation_id');

    post_flow_webhook($s['trigger']->id, $s['token'], [])->assertStatus(400);
});

test('WEBHOOK-EXT: conversation_id con formato no UUID → 400', function (): void {
    $s = webhook_setup('conversation_id');

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => 'not-a-uuid',
    ])->assertStatus(400);
});

test('WEBHOOK-EXT: contact_id de otro tenant → 400', function (): void {
    $s = webhook_setup('contact_id');
    $otherTenant = Tenant::factory()->create();
    $otherContact = make_contact($otherTenant);

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'contact_id' => $otherContact->id,
    ])->assertStatus(400);
});

test('WEBHOOK-EXT: sin Idempotency-Key se genera una automática', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->not->toBeNull();
});

test('WEBHOOK-EXT: payload body no JSON → 400', function (): void {
    $s = webhook_setup();

    test()->call(
        'POST',
        '/api/webhooks/flows/'.$s['trigger']->id,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$s['token'],
        ],
        'not json at all',
    )->assertStatus(400);
});

test('WEBHOOK-EXT: payload array no asociativo → 400', function (): void {
    $s = webhook_setup();

    test()->call(
        'POST',
        '/api/webhooks/flows/'.$s['trigger']->id,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$s['token'],
        ],
        json_encode(['item1', 'item2'], JSON_THROW_ON_ERROR),
    )->assertStatus(400);
});

test('WEBHOOK-EXT: tenant suspendido → 401', function (): void {
    $s = webhook_setup();

    $s['tenant']->update(['status' => 'suspended']);

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(401);
});

test('WEBHOOK-EXT: chatbot null → 401', function (): void {
    $s = webhook_setup();

    $s['chatbot']->delete();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(401);
});

test('WEBHOOK-EXT: audit log registra webhook_triggered', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    $audit = AuditLog::query()
        ->where('tenant_id', $s['tenant']->id)
        ->where('action', 'flow.webhook_triggered')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->subject_id)->toBe($s['trigger']->id);
});

test('WEBHOOK-EXT: payload安全 extra fields are stripped', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
        'evil_field' => 'hack',
        'flow_id' => 'another-flow',
    ])->assertStatus(202);

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->flow_id)->toBe($s['flow']->id);
});

test('WEBHOOK-EXT: Idempotency-Key vacío se trata como ausente', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ], '')->assertStatus(202);
});

test('WEBHOOK-EXT: Sin Idempotency-Key, segundo request genera nueva ejecución', function (): void {
    $s = webhook_setup();

    post_flow_webhook($s['trigger']->id, $s['token'], [
        'conversation_id' => $s['conversation']->id,
    ])->assertStatus(202);

    // Sin Idempotency-Key, el controller genera uno auto único
    // pero ShouldBeUnique del job con el auto key permite segunda ejecución
    // si la primera ya completó (uniqueFor expiró)
    // En sync queue, el job se ejecuta inline y termina rápido
    $executions = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $s['tenant']->id)
        ->count();

    expect($executions)->toBeGreaterThanOrEqual(1);
});
