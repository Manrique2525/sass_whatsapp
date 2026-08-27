<?php

declare(strict_types=1);

use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FASE 29 U3 — FlowWebhookController coverage gaps (direct E2E)
|--------------------------------------------------------------------------
|
| El resto de ramas del controller (token, payload, idempotencia, iso de
| tenant, pipeline) ya están cubiertas por FlowWebhookTest (37 tests).
| Aquí se cubren las pocas ramas no cubiertas: trigger no-UUID y trigger
| activo de tipo distinto a Webhook.
*/

test('F29-U3-FLOWWH-01: trigger id no-UUID → 401 (sin enumeración)', function (): void {
    Tenant::factory()->create();

    post_flow_webhook('not-a-uuid-trigger-id', str_repeat('a', 64), [
        'conversation_id' => '00000000-0000-0000-0000-000000000000',
    ])->assertStatus(401)
        ->assertJsonPath('code', 'WEBHOOK_UNAUTHORIZED');
});

test('F29-U3-FLOWWH-02: trigger activo de tipo no-Webhook → 401', function (): void {
    $tenant = Tenant::factory()->create();
    $chatbot = make_chatbot($tenant);
    $flow = make_flow($tenant, $chatbot, ['status' => FlowStatus::Published->value]);

    // Trigger activo pero de tipo message (no webhook)
    $trigger = make_trigger($flow, [
        'type' => FlowTriggerType::NewMessage->value,
        'config' => ['token_hash' => str_repeat('a', 64)],
        'active' => true,
    ]);

    post_flow_webhook($trigger->id, str_repeat('a', 64), [
        'conversation_id' => '00000000-0000-0000-0000-000000000000',
    ])->assertStatus(401)
        ->assertJsonPath('code', 'WEBHOOK_UNAUTHORIZED');
});
