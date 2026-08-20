<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Contacts\Enums\TagAssignmentOrigin;
use App\Domain\Contacts\Events\TagAssigned;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\StartFlowFromTag;
use App\Listeners\DispatchTagTriggerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 20 — UNIDAD 4 — TAG TRIGGER EXECUTION (ADR-050)
|--------------------------------------------------------------------------
|
| Tests de ejecución automática de flujos por asignación de tags.
| Sigue el patrón de ScheduleTriggerTest: publica grafo, crea trigger,
| instancia job inline, ejecuta, y verifica ejecución + mensajes.
|
*/

function tag_publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function tag_outbound(Tenant $tenant, string $conversationId)
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversationId)
        ->where('direction', MessageDirection::Outbound->value);
}

function tag_trigger_for(Flow $flow): Trigger
{
    return Trigger::query()->withoutTenantScope()->where('flow_id', $flow->id)->firstOrFail();
}

function tag_graph(): array
{
    $n1 = (string) Str::uuid();
    $n2 = (string) Str::uuid();

    return [$n1, $n2];
}

/*
|--------------------------------------------------------------------------
| TAG-U4-01: tag trigger válido dispara el flujo
|--------------------------------------------------------------------------
*/
test('TAG-U4-01: tag trigger válido dispara el flujo', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola por tag'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe(FlowExecutionStatus::Completed);

    $outbound = tag_outbound($tenant, $conversation->id)->get();
    expect($outbound)->toHaveCount(1)
        ->and($outbound->first()->body)->toBe('Hola por tag');
});

/*
|--------------------------------------------------------------------------
| TAG-U4-02: trigger inactivo no dispara
|--------------------------------------------------------------------------
*/
test('TAG-U4-02: trigger inactivo no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'active' => false,
        'config' => ['tags' => ['vip']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-03: flow no publicado no dispara
|--------------------------------------------------------------------------
*/
test('TAG-U4-03: flow no publicado no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    make_flow_graph($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-04: bot_paused bloquea trigger
|--------------------------------------------------------------------------
*/
test('TAG-U4-04: bot_paused bloquea trigger', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $conversation->forceFill(['bot_paused' => true])->save();

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-05: ejecución activa bloquea trigger
|--------------------------------------------------------------------------
*/
test('TAG-U4-05: ejecución activa bloquea trigger', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    TenantContext::setId($tenant->id);
    $startNode = $flow->startNode ?? $flow->nodes()->first();
    $execution = FlowExecution::query()->create([
        'flow_id' => $flow->id,
        'conversation_id' => $conversation->id,
        'tenant_id' => $tenant->id,
        'status' => FlowExecutionStatus::Running->value,
        'variables' => ['custom' => []],
        'attempts' => 0,
    ]);
    TenantContext::clear();
    $conversation->forceFill(['flow_execution_id' => $execution->id])->save();

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $executions = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->get();

    expect($executions)->toHaveCount(1)
        ->and($executions->first()->id)->toBe($execution->id);
});

/*
|--------------------------------------------------------------------------
| TAG-U4-06: nombre de tag case-sensitive no matchea
|--------------------------------------------------------------------------
*/
test('TAG-U4-06: nombre de tag case-sensitive no matchea', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['VIP']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-07: anti-recursión — origin=Flow descarta trigger
|--------------------------------------------------------------------------
*/
test('TAG-U4-07: anti-recursión — origin=Flow descarta trigger', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $event = new TagAssigned(
        tenantId: $tenant->id,
        contactId: $contact->id,
        tagId: (string) Str::uuid(),
        tagName: 'vip',
        origin: TagAssignmentOrigin::Flow,
        conversationId: null,
        originExecutionId: (string) Str::uuid(),
    );

    Queue::fake();

    $listener = new DispatchTagTriggerJob;
    $listener->handle($event);

    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-08: contacto sin conversación no dispara
|--------------------------------------------------------------------------
*/
test('TAG-U4-08: contacto sin conversación no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-09: múltiples triggers con mismo tag disparan independientemente
|--------------------------------------------------------------------------
*/
test('TAG-U4-09: múltiples triggers con mismo tag disparan independientemente', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    $flow1 = make_flow($tenant, $chatbot);
    $flow2 = make_flow($tenant, $chatbot);

    [$n1, $n2] = tag_graph();
    [$n3, $n4] = tag_graph();

    tag_publish_flow($flow1, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Flow1', 'config' => ['text' => 'Desde flow 1'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin1'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    tag_publish_flow($flow2, [
        ['id' => $n3, 'type' => 'message', 'name' => 'Flow2', 'config' => ['text' => 'Desde flow 2'], 'is_start' => true],
        ['id' => $n4, 'type' => 'end', 'name' => 'Fin2'],
    ], [
        ['from' => $n3, 'to' => $n4],
    ]);

    make_trigger($flow1, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);
    make_trigger($flow2, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $job1 = new StartFlowFromTag(
        tag_trigger_for($flow1)->id,
        $contact->id,
        'vip',
    );
    $job1->forTenant($tenant->id)->handle();

    $job2 = new StartFlowFromTag(
        tag_trigger_for($flow2)->id,
        $contact->id,
        'vip',
    );
    $job2->forTenant($tenant->id)->handle();

    $executions = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->get();

    expect($executions)->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| TAG-U4-10: audit log registra flow.tag_triggered
|--------------------------------------------------------------------------
*/
test('TAG-U4-10: audit log registra flow.tag_triggered', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenant->id)->handle();

    $audit = AuditLog::query()
        ->where('action', 'flow.tag_triggered')
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->data['trigger_id'])->toBe(tag_trigger_for($flow)->id)
        ->and($audit->data['flow_id'])->toBe($flow->id)
        ->and($audit->data['conversation_id'])->toBe($conversation->id)
        ->and($audit->data['tag_name'])->toBe('vip');
});

/*
|--------------------------------------------------------------------------
| TAG-U4-11: nombre de tag no coincidente no dispara
|--------------------------------------------------------------------------
*/
test('TAG-U4-11: nombre de tag no coincidente no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'premium',
    );
    $job->forTenant($tenant->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversation->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-12: trigger cross-tenant no dispara
|--------------------------------------------------------------------------
*/
test('TAG-U4-12: trigger cross-tenant no dispara', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $chatbot = make_chatbot($tenantA);
    $flow = make_flow($tenantA, $chatbot);
    $contact = make_contact($tenantB);
    $conversation = make_conversation($tenantB, $contact);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $job = new StartFlowFromTag(
        tag_trigger_for($flow)->id,
        $contact->id,
        'vip',
    );
    $job->forTenant($tenantB->id)->handle();

    $execution = FlowExecution::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenantB->id)
        ->first();

    expect($execution)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-13: listener origin=Manual despacha jobs por cada trigger matching
|--------------------------------------------------------------------------
*/
test('TAG-U4-13: listener origin=Manual despacha jobs por cada trigger matching', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip', 'premium']],
    ]);

    $event = new TagAssigned(
        tenantId: $tenant->id,
        contactId: (string) Str::uuid(),
        tagId: (string) Str::uuid(),
        tagName: 'vip',
        origin: TagAssignmentOrigin::Manual,
    );

    Queue::fake();

    $listener = new DispatchTagTriggerJob;
    $listener->handle($event);

    Queue::assertPushed(StartFlowFromTag::class, 1);
});

/*
|--------------------------------------------------------------------------
| TAG-U4-14: listener origin=Manual no despacha si ningún trigger matchea
|--------------------------------------------------------------------------
*/
test('TAG-U4-14: listener origin=Manual no despacha si ningún trigger matchea', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $event = new TagAssigned(
        tenantId: $tenant->id,
        contactId: (string) Str::uuid(),
        tagId: (string) Str::uuid(),
        tagName: 'premium',
        origin: TagAssignmentOrigin::Manual,
    );

    Queue::fake();

    $listener = new DispatchTagTriggerJob;
    $listener->handle($event);

    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-15: listener no despacha para triggers inactivos
|--------------------------------------------------------------------------
*/
test('TAG-U4-15: listener no despacha para triggers inactivos', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Tag->value,
        'active' => false,
        'config' => ['tags' => ['vip']],
    ]);

    $event = new TagAssigned(
        tenantId: $tenant->id,
        contactId: (string) Str::uuid(),
        tagId: (string) Str::uuid(),
        tagName: 'vip',
        origin: TagAssignmentOrigin::Manual,
    );

    Queue::fake();

    $listener = new DispatchTagTriggerJob;
    $listener->handle($event);

    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------------
| TAG-U4-16: listener no despacha para triggers de otros tenants
|--------------------------------------------------------------------------
*/
test('TAG-U4-16: listener no despacha para triggers de otros tenants', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $chatbotB = make_chatbot($tenantB);
    $flowB = make_flow($tenantB, $chatbotB);

    [$n1, $n2] = tag_graph();

    tag_publish_flow($flowB, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flowB, [
        'type' => FlowTriggerType::Tag->value,
        'config' => ['tags' => ['vip']],
    ]);

    $event = new TagAssigned(
        tenantId: $tenantA->id,
        contactId: (string) Str::uuid(),
        tagId: (string) Str::uuid(),
        tagName: 'vip',
        origin: TagAssignmentOrigin::Manual,
    );

    Queue::fake();

    $listener = new DispatchTagTriggerJob;
    $listener->handle($event);

    Queue::assertNothingPushed();
});
