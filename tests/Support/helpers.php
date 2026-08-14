<?php

declare(strict_types=1);

use App\Application\Flows\Services\FlowEngine;
use App\Application\Messages\Services\MessageService;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowExecutionStatus;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowConnection;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Messages\Models\Message;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\InvitationNotification;
use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppPhoneNumber;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * URL y helpers del webhook de WhatsApp (FASE 6). Compartidos por los tests de
 * FASE 6 (ingesta) y FASE 9 (mensajes): firma HMAC, POST con body CRUDO y
 * payload oficial de Meta.
 */
const WEBHOOK_URL = '/api/webhooks/whatsapp';

function whatsapp_secret(): string
{
    return 'test-app-secret';
}

function whatsapp_signature(string $body): string
{
    return 'sha256='.hash_hmac('sha256', $body, whatsapp_secret());
}

/**
 * POST al webhook con el body JSON CRUDO y la firma X-Hub-Signature-256
 * correcta, tal como hace Meta.
 */
function post_whatsapp_webhook(string $body): TestResponse
{
    return test()->call(
        'POST',
        WEBHOOK_URL,
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body)],
        $body,
    );
}

/**
 * Payload oficial de Meta para pruebas: un mensaje de texto opcionalmente
 * acompañado de un status delivered.
 */
function whatsapp_webhook_payload(string $messageId, string $phoneNumberId, bool $withStatus = false): string
{
    $messages = [[
        'from' => '15550000001',
        'id' => $messageId,
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => 'Hola'],
    ]];

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '104000000000000',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => [
                        'display_phone_number' => '15550000002',
                        'phone_number_id' => $phoneNumberId,
                    ],
                    'contacts' => [[
                        'profile' => ['name' => 'Cliente'],
                        'wa_id' => '15550000001',
                    ]],
                    'messages' => $messages,
                ],
            ]],
        ]],
    ];

    if ($withStatus) {
        $payload['entry'][0]['changes'][0]['value']['statuses'] = [[
            'id' => 'status-'.$messageId,
            'recipient_id' => '15550000002',
            'status' => 'delivered',
            'timestamp' => '1725000001',
        ]];
    }

    return json_encode($payload, JSON_THROW_ON_ERROR);
}

/**
 * Crea la tabla de `ScopedWidget` si no existe (por test, dentro de la
 * transacción de RefreshDatabase; se recrea al inicio de cada test).
 */
function create_scoped_widgets_table(): void
{
    if (Schema::hasTable('scoped_widgets')) {
        return;
    }

    Schema::create('scoped_widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('tenant_id');
        $table->string('name');
        $table->timestamps();
    });
}

/**
 * Inserta un widget directamente (sin pasar por el hook de `BelongsToTenant`)
 * simulando un registro creado por el tenant indicado.
 */
function insert_scoped_widget(string $tenantId, string $name): void
{
    DB::table('scoped_widgets')->insert([
        'tenant_id' => $tenantId,
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Hace al usuario miembro ACTIVO del tenant y lo deja como tenant activo.
 */
function make_tenant_member(User $user, Tenant $tenant, string $role): void
{
    $user->tenants()->attach($tenant, [
        'role' => $role,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $user->forceFill(['current_tenant_id' => $tenant->id])->save();
}

/**
 * Crea un chatbot del tenant con el TenantContext activo.
 */
function make_chatbot(Tenant $tenant, array $attributes = []): Chatbot
{
    TenantContext::setId($tenant->id);

    try {
        return Chatbot::query()->create(array_merge([
            'name' => 'Chatbot '.substr((string) Str::uuid(), 0, 8),
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

/**
 * Crea un flujo (draft por defecto) con el TenantContext activo.
 */
function make_flow(Tenant $tenant, Chatbot $chatbot, array $attributes = []): Flow
{
    TenantContext::setId($tenant->id);

    try {
        return Flow::query()->create(array_merge([
            'chatbot_id' => $chatbot->id,
            'name' => 'Flujo '.substr((string) Str::uuid(), 0, 8),
            'status' => FlowStatus::Draft->value,
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

/**
 * Crea el grafo de un flujo (nodos + conexiones) y devuelve el mapa
 * `id-cliente → FlowNode`. Los ids de los nodos los genera el cliente
 * (patrón de FASE 11: el grafo llega completo desde el editor).
 *
 * @param  array<int, array{id: string, type: string, name?: string, config?: array<string, mixed>, is_start?: bool}>  $nodes
 * @param  array<int, array{from: string, to: string, label?: string|null}>  $connections
 * @return array<string, FlowNode>
 */
function make_flow_graph(Flow $flow, array $nodes, array $connections): array
{
    TenantContext::setId((string) $flow->tenant_id);

    try {
        $map = [];

        foreach ($nodes as $node) {
            $model = new FlowNode([
                'flow_id' => $flow->id,
                'type' => $node['type'],
                'name' => $node['name'] ?? $node['id'],
                'position_x' => 0,
                'position_y' => 0,
                'config' => $node['config'] ?? null,
                'is_start' => (bool) ($node['is_start'] ?? false),
            ]);

            $model->id = $node['id'];
            $model->save();

            $map[$node['id']] = $model;
        }

        foreach ($connections as $connection) {
            FlowConnection::query()->create([
                'flow_id' => $flow->id,
                'source_node_id' => $map[$connection['from']]->id,
                'target_node_id' => $map[$connection['to']]->id,
                'label' => $connection['label'] ?? null,
            ]);
        }

        return $map;
    } finally {
        TenantContext::clear();
    }
}

/**
 * Crea un trigger (start por defecto) con el TenantContext activo.
 */
function make_trigger(Flow $flow, array $attributes = []): Trigger
{
    TenantContext::setId((string) $flow->tenant_id);

    try {
        return Trigger::query()->create(array_merge([
            'flow_id' => $flow->id,
            'type' => FlowTriggerType::Start->value,
            'priority' => 0,
            'active' => true,
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

/**
 * Crea una ejecución de flujo activa enlazada a una conversación nueva, con el
 * TenantContext activo. Para tests de API/aislamiento.
 */
function make_flow_execution(Tenant $tenant, Flow $flow, array $attributes = []): FlowExecution
{
    TenantContext::setId($tenant->id);

    try {
        $contact = Contact::query()->create([
            'name' => 'Contacto '.substr((string) Str::uuid(), 0, 8),
            'phone' => '1555'.substr(preg_replace('/\D/', '', (string) Str::uuid()), 0, 8),
        ]);

        $conversation = Conversation::query()->create([
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $startNode = $flow->startNode ?? $flow->nodes()->first();

        $execution = FlowExecution::query()->create(array_merge([
            'flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'current_node_id' => $startNode?->id,
            'status' => FlowExecutionStatus::Running->value,
            'variables' => ['custom' => []],
            'attempts' => 0,
        ], $attributes));

        $conversation->forceFill(['flow_execution_id' => $execution->id])->save();

        return $execution;
    } finally {
        TenantContext::clear();
    }
}

/**
 * Persiste un inbound de texto vía `MessageService` (dedupe por
 * `provider_message_id` aleatorio) y devuelve el mensaje.
 */
function make_inbound_message(Tenant $tenant, string $body, string $from = '15550000001'): Message
{
    $result = app(MessageService::class)->handleInboundMessage($tenant, [
        'id' => 'wamid-'.(string) Str::uuid(),
        'from' => $from,
        'timestamp' => '1725000000',
        'type' => 'text',
        'text' => ['body' => $body],
    ]);

    return $result->message;
}

/**
 * Ejecuta el motor con el TenantContext activo (como en producción lo hacen
 * los jobs `TenantAwareJob`).
 */
function run_flow_engine(Tenant $tenant, Message $message, Conversation $conversation): void
{
    TenantContext::setId($tenant->id);

    try {
        app(FlowEngine::class)->handleMessage($tenant, $message, $conversation);
    } finally {
        TenantContext::clear();
    }
}

/**
 * Crea un contacto del tenant con el TenantContext activo (como en producción).
 */
function make_contact(Tenant $tenant, array $attributes = []): Contact
{
    TenantContext::setId($tenant->id);

    try {
        return Contact::query()->create(array_merge([
            'name' => 'Cliente '.substr((string) Str::uuid(), 0, 8),
            'phone' => '+5411'.random_int(1000000, 9999999),
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

/**
 * Crea una conversación del tenant con el TenantContext activo.
 */
function make_conversation(Tenant $tenant, Contact $contact, array $attributes = []): Conversation
{
    TenantContext::setId($tenant->id);

    try {
        return Conversation::query()->create(array_merge([
            'contact_id' => $contact->id,
            'status' => 'open',
        ], $attributes));
    } finally {
        TenantContext::clear();
    }
}

/**
 * Ejecuta la operación que crea/reenvía una invitación bajo `Notification::fake`
 * y devuelve el token PLANO (para poder usar los endpoints de la invitación).
 */
function invitation_token(Closure $operation): string
{
    $token = null;

    Notification::fake();

    $operation();

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        function (InvitationNotification $notification) use (&$token): bool {
            $token = $notification->getToken();

            return true;
        },
    );

    if ($token === null) {
        throw new LogicException('El token de invitación no fue capturado.');
    }

    return $token;
}

/**
 * Crea la cuenta de WhatsApp + número de un tenant con el TenantContext activo
 * (igual que en producción lo haría un request autorizado). Devuelve ambos.
 *
 * @param  array<string, mixed>  $accountAttributes
 * @return array{account: WhatsAppAccount, phone: WhatsAppPhoneNumber}
 */
function make_whatsapp_setup(Tenant $tenant, array $accountAttributes = []): array
{
    TenantContext::setId($tenant->id);

    try {
        $account = $tenant->whatsappAccount()->create(array_merge([
            'whatsapp_business_account_id' => 'waba-1',
            'access_token' => 'token-del-tenant',
            'status' => 'connected',
        ], $accountAttributes));

        $phone = $tenant->whatsappPhoneNumbers()->create([
            'whatsapp_account_id' => $account->id,
            'phone_id' => 'phone-1',
            'display_phone_number' => '15550000002',
            'verified_name' => 'Negocio Central',
            'quality_rating' => 'GREEN',
            'status' => 'connected',
            'is_default' => true,
        ]);
    } finally {
        TenantContext::clear();
    }

    return ['account' => $account, 'phone' => $phone];
}
