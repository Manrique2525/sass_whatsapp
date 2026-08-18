<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Application\Flows\Services\AiPromptBuilder;
use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Exceptions\AIException;
use App\Domain\AI\ValueObjects\AIRequest;
use App\Domain\AI\ValueObjects\AIResponse;
use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Services\VariableGuard;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;
use Illuminate\Support\Facades\Log;

/**
 * Ejecutor del nodo `ai`: genera contenido con IA y lo guarda en custom.* (FASE 16 U2).
 *
 * - GENERA contenido y lo guarda en `custom.{output_variable}`
 * - NO envía mensajes directamente al contacto
 * - NO implementa auto_send en U2
 * - NO llama OpenAIProvider directamente por tipo concreto
 * - Dependencia única: AIProviderInterface (contract del dominio)
 *
 * Semántica:
 * 1. Validar config runtime defensivamente
 * 2. Construir contexto AI tenant-safe
 * 3. Resolver prompt con VariableResolver
 * 4. Llamar AIProviderInterface
 * 5. Sanitizar/validar output
 * 6. Guardar output en execution.variables.custom
 * 7. Devolver NodeExecutionResult::continue()
 * 8. Aplicar fallback si provider falla
 * 9. NO enviar mensajes
 *
 * Idempotencia: si el output_variable ya existe en custom y un log
 * ai_completed está registrado para este nodo, reutiliza el resultado
 * sin nueva llamada al provider.
 */
final class AiNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly AiPromptBuilder $promptBuilder,
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

        $aiRequest = $this->promptBuilder->build(
            $config,
            $context->contact,
            $context->business,
            $context->conversation,
            $context->custom,
        );

        try {
            $response = $this->provider->generateResponse($aiRequest);
            $output = $this->sanitizeOutput($response->content);

            if ($output === '') {
                Log::warning('AI provider returned empty content', [
                    'execution_id' => $context->execution->id,
                    'node_id' => $context->node->id,
                ]);

                return $this->applyFallback($context, 'AI provider returned empty content.');
            }

            $this->persistOutput($context, $outputVariable, $output);

            $this->logAiCompleted($context, $aiRequest, $response, $outputVariable);

            return NodeExecutionResult::continue();

        } catch (AIException $e) {
            Log::warning('AI provider error in flow node', [
                'execution_id' => $context->execution->id,
                'node_id' => $context->node->id,
                'error_code' => $e->errorCode()->value,
                'error_message' => $e->getMessage(),
            ]);

            return $this->applyFallback($context, $e->getMessage());

        } catch (\Throwable $e) {
            Log::error('Unexpected error in AI node', [
                'execution_id' => $context->execution->id,
                'node_id' => $context->node->id,
                'error' => $e->getMessage(),
            ]);

            return $this->applyFallback($context, $e->getMessage());
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
     * Registra log ai_completed para idempotencia y debugging seguro.
     */
    private function logAiCompleted(
        NodeExecutionContext $context,
        AIRequest $aiRequest,
        AIResponse $response,
        string $outputVariable,
    ): void {
        $context->execution->logs()->create([
            'tenant_id' => $context->tenant->id,
            'node_id' => $context->node->id,
            'event' => 'ai_completed',
            'payload' => [
                'provider' => $response->provider,
                'model' => $response->model,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'total_tokens' => $response->totalTokens,
                'output_variable' => $outputVariable,
                'output_length' => mb_strlen($response->content),
            ],
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

        $context->execution->logs()->create([
            'tenant_id' => $context->tenant->id,
            'node_id' => $context->node->id,
            'event' => 'ai_failed',
            'payload' => [
                'error' => $error,
                'fallback_applied' => $fallbackMessage !== null,
            ],
            'sequence' => $this->nextSequence($context),
        ]);

        return NodeExecutionResult::continue();
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
