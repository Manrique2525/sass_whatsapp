<?php

declare(strict_types=1);

use App\Application\Flows\Services\Executors\TagNodeExecutor;
use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 20 U1 — TagNodeExecutor regression + unit behavior
|--------------------------------------------------------------------------
*/

function create_reg_contact(Tenant $tenant, string $phone = '+529931000001'): Contact
{
    return TenantContext::withId($tenant->id, fn () => Contact::query()->create([
        'name' => 'Test Contact',
        'phone' => $phone,
    ]));
}

function create_tag_context(Tenant $tenant, Contact $contact, array $tagNames): NodeExecutionContext
{
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    $node = TenantContext::withId($tenant->id, fn () => FlowNode::query()->create([
        'flow_id' => $flow->id,
        'type' => FlowNodeType::Tag->value,
        'name' => 'Tag Node',
        'config' => ['tags' => $tagNames],
        'position_x' => 0,
        'position_y' => 0,
    ]));

    $conversation = TenantContext::withId($tenant->id, fn () => Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'status' => 'open',
    ]));

    $execution = TenantContext::withId($tenant->id, fn () => FlowExecution::query()->create([
        'tenant_id' => $tenant->id,
        'flow_id' => $flow->id,
        'conversation_id' => $conversation->id,
        'status' => FlowExecutionStatus::Running->value,
        'current_node_id' => $node->id,
        'variables' => [],
    ]));

    $business = TenantContext::withId($tenant->id, fn () => BusinessProfile::query()->firstOrCreate(
        ['tenant_id' => $tenant->id],
        ['business_name' => 'Test Business'],
    ));

    return new NodeExecutionContext(
        tenant: $tenant,
        node: $node,
        execution: $execution,
        conversation: $conversation,
        contact: $contact,
        business: $business,
    );
}

function publish_tag_flow(Flow $flow, array $nodes, array $connections): Flow
{
    make_flow_graph($flow, $nodes, $connections);
    $flow->forceFill(['status' => FlowStatus::Published->value])->save();

    return $flow;
}

test('TAG-REG-01: TagNodeExecutor assigns tags via TagService', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = create_reg_contact($tenant);

    $context = create_tag_context($tenant, $contact, ['VIP', 'interesado']);

    $executor = app(TagNodeExecutor::class);
    $result = $executor->execute($context);

    expect($result->state)->toBe('continue');

    TenantContext::setId($tenant->id);
    try {
        expect($contact->fresh()->tags()->pluck('name')->all())
            ->toEqualCanonicalizing(['VIP', 'interesado']);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-REG-02: duplicate names in config produce one tag and one pivot', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = create_reg_contact($tenant);

    $context = create_tag_context($tenant, $contact, ['VIP', 'VIP', 'interesado']);

    $executor = app(TagNodeExecutor::class);
    $executor->execute($context);

    TenantContext::setId($tenant->id);
    try {
        expect($contact->fresh()->tags()->count())->toBe(2);
        $this->assertDatabaseCount('tags', 2);
    } finally {
        TenantContext::clear();
    }
});

test('TAG-REG-03: empty tags config returns continue without DB writes', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = create_reg_contact($tenant);

    $context = create_tag_context($tenant, $contact, []);

    $executor = app(TagNodeExecutor::class);
    $result = $executor->execute($context);

    expect($result->state)->toBe('continue');
    $this->assertDatabaseCount('tags', 0);
    $this->assertDatabaseCount('contact_tag', 0);
});

test('TAG-REG-04: second execution with same tags does not create duplicates', function (): void {
    $tenant = Tenant::factory()->create();
    $contact = create_reg_contact($tenant);

    $context1 = create_tag_context($tenant, $contact, ['VIP']);
    $executor = app(TagNodeExecutor::class);
    $executor->execute($context1);

    $context2 = create_tag_context($tenant, $contact, ['VIP']);
    $executor->execute($context2);

    TenantContext::setId($tenant->id);
    try {
        $this->assertDatabaseCount('tags', 1);
        $this->assertDatabaseCount('contact_tag', 1);
    } finally {
        TenantContext::clear();
    }
});

function engine_conversation_for(Message $message): Conversation
{
    return Conversation::query()
        ->withoutTenantScope()
        ->whereKey($message->conversation_id)
        ->firstOrFail();
}

test('TAG-REG-05: FLOW-10 full engine integration with tag node', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot);

    publish_tag_flow($flow, [
        ['id' => 'n1', 'type' => 'message', 'name' => 'Inicio', 'config' => ['text' => 'Hola'], 'is_start' => true],
        ['id' => 'n2', 'type' => 'tag', 'name' => 'Etiqueta', 'config' => ['tags' => ['vip', 'interesado']]],
        ['id' => 'n3', 'type' => 'end', 'name' => 'Fin'],
    ], [
        ['from' => 'n1', 'to' => 'n2'],
        ['from' => 'n2', 'to' => 'n3'],
    ]);

    make_trigger($flow, ['type' => FlowTriggerType::Start->value]);

    $first = make_inbound_message($tenant, 'Hola');
    $conversation = engine_conversation_for($first);

    run_flow_engine($tenant, $first, $conversation);

    $execution = FlowExecution::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($execution->status)->toBe(FlowExecutionStatus::Completed);

    $contact = $conversation->contact;

    TenantContext::setId($tenant->id);
    try {
        expect($contact->tags()->pluck('name')->all())->toEqualCanonicalizing(['vip', 'interesado']);
    } finally {
        TenantContext::clear();
    }
});
