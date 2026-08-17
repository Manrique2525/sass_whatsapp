<?php

declare(strict_types=1);

use App\Application\Flows\Services\FlowEngine;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Conversations\Models\Conversation;
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
use App\Jobs\StartFlowFromSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 14 — UNIDAD 2 — TRIGGER SCHEDULE (ADR-048)
|--------------------------------------------------------------------------
*/

function sched_publish_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

function sched_outbound(Tenant $tenant, string $conversationId)
{
    return Message::query()
        ->withoutTenantScope()
        ->where('tenant_id', $tenant->id)
        ->where('conversation_id', $conversationId)
        ->where('direction', MessageDirection::Outbound->value);
}

function sched_trigger_for(Flow $flow): Trigger
{
    return Trigger::query()->withoutTenantScope()->where('flow_id', $flow->id)->firstOrFail();
}

function sched_graph(Tenant $tenant): array
{
    $n1 = (string) Str::uuid();
    $n2 = (string) Str::uuid();

    return [$n1, $n2];
}

/*
|--------------------------------------------------------------------------
| U2-SCHED-01: schedule válido dispara el flujo
|--------------------------------------------------------------------------
*/
test('U2-SCHED-01: schedule válido dispara el flujo', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola programado'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        expect($execution)->not->toBeNull()
            ->and($execution->status)->toBe(FlowExecutionStatus::Completed);

        $outbound = sched_outbound($tenant, $conversation->id)->get();
        expect($outbound)->toHaveCount(1)
            ->and($outbound->first()->body)->toBe('Hola programado');
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-02: cron fuera de ventana no dispara
|--------------------------------------------------------------------------
*/
test('U2-SCHED-02: cron fuera de ventana no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '0 * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        expect($execution)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-03: trigger inactivo no dispara
|--------------------------------------------------------------------------
*/
test('U2-SCHED-03: trigger inactivo no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'active' => false,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        expect($execution)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-04: flow no publicado no dispara
|--------------------------------------------------------------------------
*/
test('U2-SCHED-04: flow no publicado no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    make_flow_graph($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        expect($execution)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-05: bot_paused no dispara
|--------------------------------------------------------------------------
*/
test('U2-SCHED-05: bot_paused no dispara', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    TenantContext::setId($tenant->id);
    try {
        $conversation->forceFill(['bot_paused' => true])->save();
    } finally {
        TenantContext::clear();
    }

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        expect($execution)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-06: ejecución activa no duplica
|--------------------------------------------------------------------------
*/
test('U2-SCHED-06: ejecución activa no duplica', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    TenantContext::setId($tenant->id);
    try {
        $execution = FlowExecution::query()->create([
            'flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'current_node_id' => $n1,
            'status' => FlowExecutionStatus::Running->value,
            'variables' => ['custom' => []],
            'attempts' => 0,
        ]);
        $conversation->forceFill(['flow_execution_id' => $execution->id])->save();
    } finally {
        TenantContext::clear();
    }

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-07: dos ticks simultáneos no duplican ejecución
|--------------------------------------------------------------------------
*/
test('U2-SCHED-07: dos ticks simultáneos no duplican ejecución', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    $trigger = make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $lock = Cache::lock("lock:schedule:trigger:{$trigger->id}", 30);
        expect($lock->get())->toBeTrue();

        try {
            $job = new StartFlowFromSchedule($trigger->id);
            $job->forTenant($tenant->id)->handle();

            $count = FlowExecution::query()->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->where('conversation_id', $conversation->id)
                ->count();

            expect($count)->toBe(0);
        } finally {
            $lock->release();
        }
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-08: lock del trigger se libera correctamente
|--------------------------------------------------------------------------
*/
test('U2-SCHED-08: lock del trigger se libera correctamente', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    $trigger = make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule($trigger->id);
        $job->forTenant($tenant->id)->handle();

        $lock = Cache::lock("lock:schedule:trigger:{$trigger->id}", 30);
        expect($lock->get())->toBeTrue();
        $lock->release();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-09: lock de conversación se libera tras ejecución
|--------------------------------------------------------------------------
*/
test('U2-SCHED-09: lock de conversación se libera tras ejecución', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $lock = Cache::lock("lock:tenant:{$tenant->id}:flow:{$conversation->id}", 10);
        expect($lock->get())->toBeTrue();
        $lock->release();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-10: conversación inexistente → no ejecución
|--------------------------------------------------------------------------
*/
test('U2-SCHED-10: conversación inexistente no ejecuta', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    $fakeConvId = (string) Str::uuid();

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $fakeConvId],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->first();

        expect($execution)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-11: conversación de otro tenant → no ejecución
|--------------------------------------------------------------------------
*/
test('U2-SCHED-11: conversación de otro tenant no se ejecuta', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);

    [$n1, $n2] = sched_graph($tenantA);

    sched_publish_flow($flowA, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola A'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    $contactB = make_contact($tenantB);
    $conversationB = make_conversation($tenantB, $contactB);

    make_trigger($flowA, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversationB->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flowA)->id);
        $job->forTenant($tenantA->id)->handle();

        $execution = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantA->id)
            ->first();

        expect($execution)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-12: aislamiento completo tenant A/B
|--------------------------------------------------------------------------
*/
test('U2-SCHED-12: aislamiento completo tenant A/B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $chatbotA = make_chatbot($tenantA);
    $flowA = make_flow($tenantA, $chatbotA);
    $chatbotB = make_chatbot($tenantB);
    $flowB = make_flow($tenantB, $chatbotB);

    [$n1A, $n2A] = sched_graph($tenantA);
    sched_publish_flow($flowA, [
        ['id' => $n1A, 'type' => 'message', 'name' => 'A', 'config' => ['text' => 'A'], 'is_start' => true],
        ['id' => $n2A, 'type' => 'end', 'name' => 'FinA'],
    ], [
        ['from' => $n1A, 'to' => $n2A],
    ]);

    [$n1B, $n2B] = sched_graph($tenantB);
    sched_publish_flow($flowB, [
        ['id' => $n1B, 'type' => 'message', 'name' => 'B', 'config' => ['text' => 'B'], 'is_start' => true],
        ['id' => $n2B, 'type' => 'end', 'name' => 'FinB'],
    ], [
        ['from' => $n1B, 'to' => $n2B],
    ]);

    $contactA = make_contact($tenantA);
    $convA = make_conversation($tenantA, $contactA);
    $contactB = make_contact($tenantB);
    $convB = make_conversation($tenantB, $contactB);

    make_trigger($flowA, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $convA->id],
    ]);
    make_trigger($flowB, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $convB->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $jobA = new StartFlowFromSchedule(sched_trigger_for($flowA)->id);
        $jobA->forTenant($tenantA->id)->handle();

        $jobB = new StartFlowFromSchedule(sched_trigger_for($flowB)->id);
        $jobB->forTenant($tenantB->id)->handle();

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenantA->id)
            ->where('conversation_id', $convA->id)
            ->count())->toBe(1);

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenantA->id)
            ->where('conversation_id', $convB->id)
            ->count())->toBe(0);

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenantB->id)
            ->where('conversation_id', $convB->id)
            ->count())->toBe(1);

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenantB->id)
            ->where('conversation_id', $convA->id)
            ->count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-13: múltiples triggers schedule independientes
|--------------------------------------------------------------------------
*/
test('U2-SCHED-13: múltiples triggers schedule independientes', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);

    $flow1 = make_flow($tenant, $chatbot, ['name' => 'Flow 1']);
    $flow2 = make_flow($tenant, $chatbot, ['name' => 'Flow 2']);

    [$n1A, $n2A] = sched_graph($tenant);
    sched_publish_flow($flow1, [
        ['id' => $n1A, 'type' => 'message', 'name' => 'F1', 'config' => ['text' => 'Flow 1'], 'is_start' => true],
        ['id' => $n2A, 'type' => 'end', 'name' => 'Fin1'],
    ], [
        ['from' => $n1A, 'to' => $n2A],
    ]);

    [$n1B, $n2B] = sched_graph($tenant);
    sched_publish_flow($flow2, [
        ['id' => $n1B, 'type' => 'message', 'name' => 'F2', 'config' => ['text' => 'Flow 2'], 'is_start' => true],
        ['id' => $n2B, 'type' => 'end', 'name' => 'Fin2'],
    ], [
        ['from' => $n1B, 'to' => $n2B],
    ]);

    $contact = make_contact($tenant);
    $conv1 = make_conversation($tenant, $contact);
    $conv2 = make_conversation($tenant, $contact);

    make_trigger($flow1, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conv1->id],
    ]);
    make_trigger($flow2, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conv2->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job1 = new StartFlowFromSchedule(sched_trigger_for($flow1)->id);
        $job1->forTenant($tenant->id)->handle();

        $job2 = new StartFlowFromSchedule(sched_trigger_for($flow2)->id);
        $job2->forTenant($tenant->id)->handle();

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conv1->id)
            ->count())->toBe(1);

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conv2->id)
            ->count())->toBe(1);

        expect(FlowExecution::query()->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->count())->toBe(2);
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-14: prioridad/precedencia no rompe triggers existentes
|--------------------------------------------------------------------------
*/
test('U2-SCHED-14: keyword y start siguen funcionando tras schedule', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);

    $flowSchedule = make_flow($tenant, $chatbot, ['name' => 'Scheduled']);
    [$n1Sch, $n2Sch] = sched_graph($tenant);
    sched_publish_flow($flowSchedule, [
        ['id' => $n1Sch, 'type' => 'message', 'name' => 'SchMsg', 'config' => ['text' => 'Scheduled!'], 'is_start' => true],
        ['id' => $n2Sch, 'type' => 'end', 'name' => 'FinSch'],
    ], [
        ['from' => $n1Sch, 'to' => $n2Sch],
    ]);

    $flowKeyword = make_flow($tenant, $chatbot, ['name' => 'Keyword']);
    [$n1Kw, $n2Kw] = sched_graph($tenant);
    sched_publish_flow($flowKeyword, [
        ['id' => $n1Kw, 'type' => 'message', 'name' => 'KwMsg', 'config' => ['text' => 'Keyword matched!'], 'is_start' => true],
        ['id' => $n2Kw, 'type' => 'end', 'name' => 'FinKw'],
    ], [
        ['from' => $n1Kw, 'to' => $n2Kw],
    ]);

    $contact = make_contact($tenant);
    $convSch = make_conversation($tenant, $contact);

    make_trigger($flowSchedule, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '0 * * * *', 'conversation_id' => $convSch->id],
    ]);

    make_trigger($flowKeyword, [
        'type' => FlowTriggerType::Keyword->value,
        'keyword' => 'ofertas',
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $jobSch = new StartFlowFromSchedule(sched_trigger_for($flowSchedule)->id);
        $jobSch->forTenant($tenant->id)->handle();

        $executionSch = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $convSch->id)
            ->first();

        expect($executionSch)->toBeNull();

        $message = make_inbound_message($tenant, 'Quiero ver ofertas');
        $convKw = Conversation::query()->withoutTenantScope()
            ->whereKey($message->conversation_id)
            ->firstOrFail();

        TenantContext::setId($tenant->id);
        try {
            app(FlowEngine::class)
                ->handleMessage($tenant, $message, $convKw);
        } finally {
            TenantContext::clear();
        }

        $executionKw = FlowExecution::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $convKw->id)
            ->first();

        expect($executionKw)->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-15: command despacha jobs para triggers que matchean
|--------------------------------------------------------------------------
*/
test('U2-SCHED-15: command despacha jobs correctos', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $exitCode = Artisan::call('flow:fire-schedule-triggers');

        expect($exitCode)->toBe(0);
        Queue::assertPushed(StartFlowFromSchedule::class, 1);
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-16: command no despacha cuando cron no matchea
|--------------------------------------------------------------------------
*/
test('U2-SCHED-16: command no despacha cuando cron no matchea', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '0 * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        Artisan::call('flow:fire-schedule-triggers');

        Queue::assertNotPushed(StartFlowFromSchedule::class);
    } finally {
        Carbon::setTestNow();
    }
});

/*
|--------------------------------------------------------------------------
| U2-SCHED-17: audit log se registra al disparar schedule
|--------------------------------------------------------------------------
*/
test('U2-SCHED-17: audit log registra schedule_triggered', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);
    $contact = make_contact($tenant);
    $conversation = make_conversation($tenant, $contact);

    [$n1, $n2] = sched_graph($tenant);

    sched_publish_flow($flow, [
        ['id' => $n1, 'type' => 'message', 'name' => 'Saludo', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => $n2, 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => $n1, 'to' => $n2],
    ]);

    make_trigger($flow, [
        'type' => FlowTriggerType::Schedule->value,
        'config' => ['cron' => '* * * * *', 'conversation_id' => $conversation->id],
    ]);

    $now = Carbon::parse('2026-08-15 10:30:00');
    Carbon::setTestNow($now);

    try {
        $job = new StartFlowFromSchedule(sched_trigger_for($flow)->id);
        $job->forTenant($tenant->id)->handle();

        $audit = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'flow.schedule_triggered')
            ->first();

        expect($audit)->not->toBeNull()
            ->and($audit->data['trigger_id'])->toBe(sched_trigger_for($flow)->id)
            ->and($audit->data['flow_id'])->toBe($flow->id)
            ->and($audit->data['conversation_id'])->toBe($conversation->id);
    } finally {
        Carbon::setTestNow();
    }
});
