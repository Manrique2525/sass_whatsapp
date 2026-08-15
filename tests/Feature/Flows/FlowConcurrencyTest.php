<?php

declare(strict_types=1);

use App\Application\Flows\Services\FlowEngine;
use App\Application\Messages\Services\MessageService;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\ContinueFlowExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 13 â€” UNIDAD 5 â€” CONCURRENCIA (VAR-24/25/26)
|--------------------------------------------------------------------------
| El motor serializa por conversaciÃ³n bajo el lock Redis
| `lock:tenant:{id}:flow:{conversation_id}` (ADR-015/037): estas pruebas
| verifican que la captura de variables nunca se pierde y que la reprocesaciÃ³n
| del mismo inbound es idempotente (barrera `last_inbound_message_id` +
| dedupe por `provider_message_id`).
*/

function concurrency_outbound(Tenant $tenant, string $conversationId)
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversationId)
        ->where('direction', MessageDirection::Outbound->value);
}

function concurrency_publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function concurrency_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

function concurrency_run_engine(Tenant $tenant, Message $message, Conversation $conversation): void
{
    TenantContext::setId($tenant->id);

    try {
        app(FlowEngine::class)->handleMessage($tenant, $message, $conversation);
    } finally {
        TenantContext::clear();
    }
}

function concurrency_continue(Tenant $tenant, FlowExecution $execution, string $mode = 'delay'): void
{
    TenantContext::setId($tenant->id);

    try {
        app(FlowEngine::class)->continueExecution($tenant, $execution, $mode);
    } finally {
        TenantContext::clear();
    }
}

test('VAR-24: dos mensajes en la misma conversaciÃ³n acumulan variables sin pÃ©rdida (un solo execution)', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    concurrency_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Nombre', 'config' => ['prompt' => 'Â¿Nombre?', 'field' => 'nombre']],
        ['id' => 'n3', 'type' => 'question', 'name' => 'Ciudad', 'config' => ['prompt' => 'Â¿Ciudad?', 'field' => 'ciudad']],
        ['id' => 'n4', 'type' => 'message', 'name' => 'Resumen', 'config' => ['text' => '{{custom.nombre}} de {{custom.ciudad}}']],
        ['id' => 'n5', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
        ['from' => 'n4', 'to' => 'n5'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = concurrency_conversation_for($first);

    concurrency_run_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    // Segundo mensaje en la misma conversaciÃ³n, en rÃ¡pida sucesiÃ³n.
    $answer = make_inbound_message($tenant, 'Ana', '15550000001');
    $conversation->refresh();
    concurrency_run_engine($tenant, $answer, $conversation);

    $execution->refresh();
    expect($execution->variables['custom']['nombre'])->toBe('Ana')
        ->and($execution->status)->toBe(FlowExecutionStatus::Waiting);

    // Tercer mensaje: captura la segunda variable.
    $city = make_inbound_message($tenant, 'CÃ³rdoba', '15550000001');
    $conversation->refresh();
    concurrency_run_engine($tenant, $city, $conversation);

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($execution->variables['custom'])->toBe(['nombre' => 'Ana', 'ciudad' => 'CÃ³rdoba']);

    // Exactamente UN execution activo por conversaciÃ³n (el lock evita duplicados).
    expect(FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);

    $outbound = concurrency_outbound($tenant, $conversation->id)->orderBy('created_at')->get();

    expect($outbound->last()->body)->toBe('Ana de CÃ³rdoba');
});

test('VAR-25: reprocesar el MISMO inbound (mismo provider_message_id) es idempotente', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    concurrency_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Nombre', 'config' => ['prompt' => 'Â¿Nombre?', 'field' => 'nombre']],
        ['id' => 'n3', 'type' => 'message', 'name' => 'Gracias', 'config' => ['text' => 'Gracias {{custom.nombre}}']],
        ['id' => 'n4', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $providerId = 'wamid-duplicado-'.(string) Str::uuid();
    $event = [
        'id' => $providerId,
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => 'Hola'],
    ];

    // Dedupe de ingesta: dos entregas de Meta con el mismo id â†’ UNA fila.
    $firstResult = app(MessageService::class)->handleInboundMessage($tenant, $event);
    $secondResult = app(MessageService::class)->handleInboundMessage($tenant, $event);

    expect($firstResult->message->id)->toBe($secondResult->message->id)
        ->and(Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->count())->toBe(1);

    $message = $firstResult->message;
    $conversation = concurrency_conversation_for($message);

    concurrency_run_engine($tenant, $message, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($execution->status)->toBe(FlowExecutionStatus::Waiting);

    $outboundBefore = concurrency_outbound($tenant, $conversation->id)->count();

    // Reentrega del MISMO mensaje mientras la ejecuciÃ³n sigue activa: barrera
    // `last_inbound_message_id` â†’ no-op (no reenvÃ­a el prompt ni re-captura).
    $conversation->refresh();
    concurrency_run_engine($tenant, $message, $conversation);

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Waiting)
        ->and(concurrency_outbound($tenant, $conversation->id)->count())->toBe($outboundBefore);

    $answer = make_inbound_message($tenant, 'Ana', '15550000001');
    $conversation->refresh();
    concurrency_run_engine($tenant, $answer, $conversation);

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($execution->variables['custom']['nombre'])->toBe('Ana');

    $outbound = concurrency_outbound($tenant, $conversation->id)->orderBy('created_at')->get();

    expect($outbound->last()->body)->toBe('Gracias Ana');
});

test('VAR-26: la variable capturada persiste a travÃ©s de waiting â†’ delay â†’ resume bajo el mismo lock', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    concurrency_publish_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'question', 'name' => 'Nombre', 'config' => ['prompt' => 'Â¿Nombre?', 'field' => 'nombre']],
        ['id' => 'n3', 'type' => 'delay', 'name' => 'Espera', 'config' => ['seconds' => 30]],
        ['id' => 'n4', 'type' => 'message', 'name' => 'Resumen', 'config' => ['text' => 'Hola {{custom.nombre}}']],
        ['id' => 'n5', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
        ['from' => 'n3', 'to' => 'n4'],
        ['from' => 'n4', 'to' => 'n5'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = concurrency_conversation_for($first);

    concurrency_run_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    $answer = make_inbound_message($tenant, 'Ana', '15550000001');
    $conversation->refresh();
    concurrency_run_engine($tenant, $answer, $conversation);

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Waiting)
        ->and($execution->variables['custom']['nombre'])->toBe('Ana');

    Queue::assertPushed(ContinueFlowExecution::class, fn (ContinueFlowExecution $job): bool => $job->mode === 'delay');

    // Resume programado (worker): la variable capturada no se pierde.
    concurrency_continue($tenant, $execution, 'delay');

    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and($execution->variables['custom']['nombre'])->toBe('Ana');

    $outbound = concurrency_outbound($tenant, $conversation->id)->orderBy('created_at')->get();

    expect($outbound->last()->body)->toBe('Hola Ana');

    // Una continuaciÃ³n duplicada tras completar es un no-op seguro.
    $outboundCount = $outbound->count();
    concurrency_continue($tenant, $execution, 'delay');
    $execution->refresh();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed)
        ->and(concurrency_outbound($tenant, $conversation->id)->count())->toBe($outboundCount);

    // El lock de conversaciÃ³n se libera tras el ciclo (otro worker puede tomarlo).
    $lock = Cache::lock("lock:tenant:{$tenant->id}:flow:{$conversation->id}", 10);
    expect($lock->get())->toBeTrue();

    if ($lock->owner() !== null) {
        $lock->release();
    }
});
