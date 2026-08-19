<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Application\Flows\Services\AiPromptBuilder;
use App\Application\KnowledgeBase\Contracts\KnowledgeSearchServiceInterface;
use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Enums\AIErrorCode;
use App\Domain\AI\Exceptions\AIException;
use App\Domain\AI\ValueObjects\TelemetryPayload;
use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Services\VariableGuard;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;
use App\Domain\KnowledgeBase\ValueObjects\KnowledgeContext;
use Illuminate\Support\Facades\Log;

/**
 * Ejecutor del nodo `ai`: genera contenido con IA y lo guarda en custom.* (FASE 16 U2+U4).
 *
 * - GENERA contenido y lo guarda en `custom.{output_variable}`
 * - NO envía mensajes directamente al contacto
 * - NO implementa auto_send en U2
 * - NO llama OpenAIProvider directamente por tipo concreto
 * - Dependencia primaria: AIProviderInterface (contract del dominio)
 * - Dependencia RAG: KnowledgeSearchService (FASE 17 U3.4)
 *
 * Semántica:
 * 1. Validar config runtime defensivamente
 * 2. Resolver knowledge context si knowledge_base_id configurado (RAG)
 * 3. Construir contexto AI tenant-safe
 * 4. Resolver prompt con VariableResolver
 * 5. Llamar AIProviderInterface
 * 6. Sanitizar/validar output
 * 7. Guardar output en execution.variables.custom
 * 8. Devolver NodeExecutionResult::continue()
 * 9. Aplicar fallback si provider falla
 * 10. NO enviar mensajes
 * 11. Registrar telemetría segura (sin PII) vía TelemetryPayload (U4)
 *
 * Idempotencia: si el output_variable ya existe en custom y un log
 * ai_completed está registrado para este nodo, reutiliza el resultado
 * sin nueva llamada al provider (y sin duplicar telemetría).
 *
 * RAG (FASE 17 U3.4): si el nodo tiene knowledge_base_id configurado,
 * recupera chunks semánticos y los inyecta como contexto no confiable
 * en el prompt. Si la búsqueda falla, continúa sin RAG.
 */
final class AiNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly AiPromptBuilder $promptBuilder,
        private readonly KnowledgeSearchServiceInterface $searchService,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::AI;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        if ($context->conversation->bot_paused) {
            Log::info('AI node skipped: bot_paused is true', [
                'execution_id' => $context->execution->id,
                'node_id' => $context->node->id,
            ]);

            return NodeExecutionResult::continue();
        }

        $config = is_array($context->node->config) ? $context->node->config : [];

        $outputVariable = $this->validateOutputVariable($config);

        if ($outputVariable === null) {
            return $this->applyFallback(
                $context,
                'AI output variable is invalid.',
            );
        }

        if ($this->isAlreadyCompleted($context, $outputVariable)) {
            return NodeExecutionResult::continue();
        }

        $knowledgeContext = $this->resolveKnowledgeContext($config, $context);

        $aiRequest = $this->promptBuilder->build(
            $config,
            $context->contact,
            $context->business,
            $context->conversation,
            $context->custom,
            $knowledgeContext,
        );

        $startNs = hrtime(true);

        try {
            $response = $this->provider->generateResponse($aiRequest);
            $latencyMs = (int) ((hrtime(true) - $startNs) / 1_000_000);
            $output = $this->sanitizeOutput($response->content);

            if ($output === '') {
                Log::warning('AI provider returned empty content', [
                    'execution_id' => $context->execution->id,
                    'node_id' => $context->node->id,
                ]);

                return $this->applyFallback(
                    $context,
                    'AI provider returned empty content.',
                    $latencyMs,
                );
            }

            $this->persistOutput($context, $outputVariable, $output);

            $telemetry = TelemetryPayload::fromResponse(
                $response,
                $latencyMs,
            );

            $this->logAiCompleted($context, $telemetry, $outputVariable, $knowledgeContext);

            return NodeExecutionResult::continue();

        } catch (AIException $e) {
            $latencyMs = (int) ((hrtime(true) - $startNs) / 1_000_000);

            Log::warning('AI provider error in flow node', [
                'execution_id' => $context->execution->id,
                'node_id' => $context->node->id,
                'error_code' => $e->errorCode()->value,
                'error_message' => $e->getMessage(),
            ]);

            return $this->applyFallback($context, $e->getMessage(), $latencyMs, $e->errorCode());

        } catch (\Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $startNs) / 1_000_000);

            Log::error('Unexpected error in AI node', [
                'execution_id' => $context->execution->id,
                'node_id' => $context->node->id,
                'error' => $e->getMessage(),
            ]);

            return $this->applyFallback($context, $e->getMessage(), $latencyMs);
        }
    }

    /**
     * Valida y normaliza output_variable del config.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateOutputVariable(array $config): ?string
    {
        $raw = $config['output_variable'] ?? null;

        if ($raw === null || ! is_string($raw)) {
            return null;
        }

        $normalized = VariableGuard::normalizeKey($raw);

        if (! VariableGuard::isValidKey($normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Resuelve el contexto de conocimiento RAG si knowledge_base_id está configurado (FASE 17 U3.4).
     *
     * Si la búsqueda falla por error interno: retorna null (continue without RAG).
     * Nunca lanza excepción — RAG es enriquecimiento opcional.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveKnowledgeContext(
        array $config,
        NodeExecutionContext $context,
    ): ?KnowledgeContext {
        $knowledgeBaseId = $config['knowledge_base_id'] ?? null;

        if (! is_string($knowledgeBaseId) || $knowledgeBaseId === '') {
            return null;
        }

        $query = $this->resolveSearchQuery($config, $context);

        if ($query === '') {
            return null;
        }

        try {
            $result = $this->searchService->search(
                tenantId: $context->tenant->id,
                knowledgeBaseId: $knowledgeBaseId,
                query: $query,
            );

            if ($result->isEmpty()) {
                Log::info('RAG search returned empty results', [
                    'execution_id' => $context->execution->id,
                    'node_id' => $context->node->id,
                    'knowledge_base_id' => $knowledgeBaseId,
                ]);

                return null;
            }

            return new KnowledgeContext(
                chunks: $result->chunks,
                totalCount: $result->totalCount,
                searchDurationMs: $result->searchDurationMs,
            );

        } catch (\Throwable $e) {
            Log::warning('RAG search failed, continuing without knowledge context', [
                'execution_id' => $context->execution->id,
                'node_id' => $context->node->id,
                'knowledge_base_id' => $knowledgeBaseId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resuelve la query de búsqueda semántica: usa el prompt USER resuelto por VariableResolver.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveSearchQuery(
        array $config,
        NodeExecutionContext $context,
    ): string {
        $resolvedPrompt = $this->promptBuilder->resolvePromptOnly(
            (string) ($config['prompt'] ?? ''),
            $context->contact,
            $context->business,
            $context->conversation,
            $context->custom,
        );

        $trimmed = trim($resolvedPrompt);

        if ($trimmed === '') {
            return '';
        }

        return mb_substr($trimmed, 0, 2000);
    }

    /**
     * Idempotencia: si el output ya existe y hay un log ai_completed,
     * reutiliza sin nueva llamada al provider.
     */
    private function isAlreadyCompleted(
        NodeExecutionContext $context,
        string $outputVariable,
    ): bool {
        $freshCustom = $context->execution->fresh()->variables['custom'] ?? [];
        $existingOutput = $freshCustom[$outputVariable] ?? null;

        if ($existingOutput === null) {
            return false;
        }

        $hasLog = $context->execution->logs()
            ->where('node_id', $context->node->id)
            ->where('event', 'ai_completed')
            ->exists();

        return $hasLog;
    }

    /**
     * Sanitiza el output del provider: elimina control chars, trunca.
     */
    private function sanitizeOutput(string $content): string
    {
        $trimmed = trim($content);

        $sanitized = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $trimmed);

        return VariableGuard::truncateValue($sanitized);
    }

    /**
     * Persiste el output en execution.variables.custom.*
     */
    private function persistOutput(
        NodeExecutionContext $context,
        string $outputVariable,
        string $output,
    ): void {
        $variables = $context->execution->variables;
        $variables['custom'][$outputVariable] = $output;
        $context->execution->forceFill(['variables' => $variables])->save();
    }

    /**
     * Registra log ai_completed con telemetría segura (sin PII).
     */
    private function logAiCompleted(
        NodeExecutionContext $context,
        TelemetryPayload $telemetry,
        string $outputVariable,
        ?KnowledgeContext $knowledgeContext = null,
    ): void {
        $payload = $telemetry->toArray();
        $payload['output_variable'] = $outputVariable;
        $ragUsed = $knowledgeContext !== null && ! $knowledgeContext->isEmpty();
        $payload['rag_used'] = $ragUsed;
        $payload['retrieved_chunks_count'] = $ragUsed ? $knowledgeContext->totalCount : 0;

        $context->execution->logs()->create([
            'tenant_id' => $context->tenant->id,
            'node_id' => $context->node->id,
            'event' => 'ai_completed',
            'payload' => $payload,
            'sequence' => $this->nextSequence($context),
        ]);
    }

    private function nextSequence(NodeExecutionContext $context): int
    {
        return (int) $context->execution->logs()->max('sequence') + 1;
    }

    /**
     * Aplica fallback cuando el provider falla.
     *
     * Si config.fallback_message tiene valor: lo resuelve y guarda.
     * Si no: usa el fallback central de config/ai.php.
     * Si no hay fallback: output variable queda vacía, flow continúa.
     */
    private function applyFallback(
        NodeExecutionContext $context,
        string $error,
        int $latencyMs = 0,
        ?AIErrorCode $errorCode = null,
    ): NodeExecutionResult {
        $config = is_array($context->node->config) ? $context->node->config : [];
        $outputVariable = VariableGuard::normalizeKey($config['output_variable'] ?? '');

        if (! VariableGuard::isValidKey($outputVariable)) {
            return NodeExecutionResult::continue();
        }

        $fallbackMessage = $this->resolveFallbackMessage($config, $context);

        if ($fallbackMessage !== null) {
            $this->persistOutput($context, $outputVariable, $fallbackMessage);
        }

        $telemetry = TelemetryPayload::fromError(
            $errorCode,
            $latencyMs,
            fallbackUsed: $fallbackMessage !== null,
        );

        $this->logAiFailed($context, $telemetry, $error);

        return NodeExecutionResult::continue();
    }

    /**
     * Registra log ai_failed con telemetría segura (sin PII).
     */
    private function logAiFailed(
        NodeExecutionContext $context,
        TelemetryPayload $telemetry,
        string $error,
    ): void {
        $payload = $telemetry->toArray();
        $payload['error'] = $this->sanitizeErrorCode($error);

        $context->execution->logs()->create([
            'tenant_id' => $context->tenant->id,
            'node_id' => $context->node->id,
            'event' => 'ai_failed',
            'payload' => $payload,
            'sequence' => $this->nextSequence($context),
        ]);
    }

    private function sanitizeErrorCode(string $error): string
    {
        if (str_contains($error, 'rate_limit')) {
            return 'rate_limit';
        }

        if (str_contains($error, 'timeout') || str_contains($error, 'cURL')) {
            return 'provider_timeout';
        }

        if (str_contains($error, 'invalid_api_key') || str_contains($error, '401')) {
            return 'auth_failed';
        }

        if (str_contains($error, 'dimension')) {
            return 'dimension_mismatch';
        }

        return 'provider_error';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveFallbackMessage(
        array $config,
        NodeExecutionContext $context,
    ): ?string {
        $nodeFallback = $config['fallback_message'] ?? null;

        if (is_string($nodeFallback) && trim($nodeFallback) !== '') {
            return $this->sanitizeOutput($nodeFallback);
        }

        $globalFallback = config('ai.fallback_message');

        if (is_string($globalFallback) && trim($globalFallback) !== '') {
            return $this->sanitizeOutput($globalFallback);
        }

        return null;
    }
}
