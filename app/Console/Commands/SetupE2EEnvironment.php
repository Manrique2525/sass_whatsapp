<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Flows\Services\FlowEngine;
use App\Application\Messages\Services\MessageService;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Enums\FlowStatus;
use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Chatbot;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowConnection;
use App\Domain\Flows\Models\FlowNode;
use App\Domain\Flows\Models\Trigger;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Testing\E2EEnvironmentGuard;
use Database\Seeders\E2ETenantSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Prepara el entorno E2E (Playwright, FASE 30 / ADR-110).
 *
 * Solo se ejecuta bajo el guard E2E (APP_ENV=e2e + BD *_e2e_test + Redis E2E):
 *
 *  - migra desde cero la BD E2E (nunca toca dev/test)
 *  - siembra los fixtures de tenants/usuarios
 *  - vacía SOLO el índice lógico de Redis dedicado E2E (nosin flushes globales)
 *  - prepara el directorio de storage dedicado E2E
 */
final class SetupE2EEnvironment extends Command
{
    protected $signature = 'e2e:setup {--skip-migrate : No ejecuta migrate:fresh (usa fixtures ya migrados)}';

    protected $description = 'Prepara el entorno E2E (Playwright): guard de seguridad, migración, seed y limpieza de Redis E2E';

    public function handle(): int
    {
        E2EEnvironmentGuard::assertSafe();

        $redisIndex = E2EEnvironmentGuard::E2E_REDIS_INDEX;
        $this->info(sprintf('E2E guard OK: APP_ENV=%s, BD=%s, Redis index=%d.',
            app()->environment(),
            config('database.connections.'.config('database.default').'.database'),
            $redisIndex,
        ));

        if (! $this->option('skip-migrate')) {
            $this->info('Ejecutando migrate:fresh sobre la BD E2E...');
            $this->call('migrate:fresh', ['--force' => true]);
        }

        $this->info('Sembrando fixtures E2E (E2ETenantSeeder)...');
        $this->call('db:seed', ['--class' => E2ETenantSeeder::class, '--force' => true]);

        $this->info('Preparando fixture de handoff humano (FlowEngine Start -> Human)...');
        $this->prepareHumanHandoffFixture();

        $flushed = $this->flushScopedRedis($redisIndex);
        $this->info(sprintf('Redis E2E (índice %d) vaciado (%d llaves).', $redisIndex, $flushed));

        $this->prepareStorage();

        $this->info('Entorno E2E listo para Playwright.');

        return self::SUCCESS;
    }

    /**
     * Dispara el handoff humano REAL sobre la conversación handoff sembrada por
     * el E2ETenantSeeder (FASE 30 U2).
     *
     * El estado de handoff NO se fuerza en la BD: se construye el flujo publicado
     * Start -> Message -> Human con trigger de inicio y se inyecta el primer
     * inbound sintético de ese contacto. El `FlowEngine` (con QUEUE_CONNECTION=sync)
     * ejecuta el nodo Message y llega al nodo Human, donde `HumanHandoffService`
     * persiste bot_paused=true + handoff_requested_at. El proveedor de WhatsApp
     * resuelto en el runtime E2E es `FakeWhatsAppProvider`, así que ningún envío
     * alcanza la Graph API de Meta (fail-closed).
     */
    private function prepareHumanHandoffFixture(): void
    {
        $tenant = Tenant::query()->find(E2ETenantSeeder::TENANT_A_ID);

        if ($tenant === null) {
            $this->warn('Tenant A no encontrado; no se prepara el fixture de handoff.');

            return;
        }

        $phone = '+15550001003';
        $nodeMessageId = 'e2e00000-0000-4000-8000-000000000001';
        $nodeHumanId = 'e2e00000-0000-4000-8000-000000000002';

        TenantContext::setId($tenant->id);

        try {
            $chatbot = Chatbot::query()->create([
                'name' => 'E2E Handoff Bot ('.substr((string) Str::uuid(), 0, 8).')',
            ]);

            $flow = Flow::query()->create([
                'chatbot_id' => $chatbot->id,
                'name' => 'E2E Handoff Flow ('.substr((string) Str::uuid(), 0, 8).')',
                'status' => FlowStatus::Published,
                'config' => ['max_steps' => 20],
            ]);

            $nodeMessage = new FlowNode([
                'flow_id' => $flow->id,
                'type' => FlowNodeType::Message,
                'name' => 'Inicio',
                'position_x' => 0,
                'position_y' => 0,
                'config' => ['text' => 'Hola, ¿en qué te ayudo?'],
                'is_start' => true,
            ]);
            $nodeMessage->id = $nodeMessageId;
            $nodeMessage->save();

            $nodeHuman = new FlowNode([
                'flow_id' => $flow->id,
                'type' => FlowNodeType::Human,
                'name' => 'Humano',
                'position_x' => 0,
                'position_y' => 0,
                'config' => ['handoff_message' => 'Un agente te atenderá en breve.'],
                'is_start' => false,
            ]);
            $nodeHuman->id = $nodeHumanId;
            $nodeHuman->save();

            FlowConnection::query()->create([
                'flow_id' => $flow->id,
                'source_node_id' => $nodeMessageId,
                'target_node_id' => $nodeHumanId,
                'label' => null,
            ]);

            Trigger::query()->create([
                'flow_id' => $flow->id,
                'type' => FlowTriggerType::Start,
                'keyword' => null,
                'config' => null,
                'priority' => 0,
                'active' => true,
            ]);

            $result = app(MessageService::class)->handleInboundMessage($tenant, [
                'id' => 'wamid-e2e-handoff-'.(string) Str::uuid(),
                'from' => $phone,
                'timestamp' => (string) now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Hola, necesito hablar con un humano.'],
            ]);

            if ($result->message === null) {
                throw new \RuntimeException('No se pudo crear el inbound sintético del handoff.');
            }

            $conversation = Conversation::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->whereKey($result->message->conversation_id)
                ->first();

            if ($conversation === null) {
                throw new \RuntimeException('No se resolvió la conversación del handoff.');
            }

            app(FlowEngine::class)->handleMessage($tenant, $result->message, $conversation);

            $conversation->refresh();

            if (! $conversation->bot_paused || $conversation->handoff_requested_at === null) {
                throw new \RuntimeException('El FlowEngine no dejó la conversación en estado de handoff (bot_paused/handoff_requested_at).');
            }

            if ($conversation->status !== ConversationStatus::Open) {
                throw new \RuntimeException('La conversación handoff no quedó en estado Open tras el engine.');
            }

            $this->info('Handoff humano listo: bot_paused, handoff_requested_at y conversación Open.');
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * Vacía únicamente el índice lógico de Redis conectado (dedicado E2E).
     * `flushdb()` actúa sobre el índice seleccionado al conectar (el de la
     * config, ya validado por el guard), nunca sobre todos (sin FLUSHALL; no
     * toca índices de dev 0/1 ni de pgsql 14).
     */
    private function flushScopedRedis(int $expectedIndex): int
    {
        $databaseIndex = (int) config('database.redis.default.database', config('database.redis.options.database', 0));

        if ($databaseIndex !== $expectedIndex) {
            throw new \RuntimeException(sprintf(
                'Redis E2E: índice configurado %d distinto del esperado %d. Abortando.',
                $databaseIndex,
                $expectedIndex,
            ));
        }

        Redis::connection()->flushdb();

        return 0;
    }

    private function prepareStorage(): void
    {
        $disk = config('filesystems.default');
        $this->info(sprintf('Storage E2E: disco "%s" listo.', $disk));
    }
}
