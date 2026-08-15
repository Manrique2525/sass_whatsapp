<?php

declare(strict_types=1);

namespace App\Application\Flows\Services\Executors;

use App\Application\Audit\Services\AuditLogger;
use App\Domain\Flows\Contracts\NodeExecutorInterface;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Exceptions\FlowWebhookRequestFailedException;
use App\Domain\Flows\Models\FlowExecution;
use App\Domain\Flows\Services\VariableResolver;
use App\Domain\Flows\Services\WebhookUrlGuard;
use App\Domain\Flows\ValueObjects\NodeExecutionContext;
use App\Domain\Flows\ValueObjects\NodeExecutionResult;
use Illuminate\Http\Client\Factory as Http;

/**
 * Ejecutor del nodo `webhook`: llama a un servicio externo del negocio.
 *
 * Seguridad (FLOW-22): el URL pasa por `WebhookUrlGuard` (bloquea hosts
 * locales / IPs privadas) antes de resolver DNS, y el cliente HTTP no sigue
 * redirecciones (evita SSRF por redirección) con timeouts cortos. Los valores
 * del payload/headers resuelven variables `{{...}}`; el método y las cabeceras
 * fijas están definidos en la config del nodo.
 */
final class WebhookNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly WebhookUrlGuard $guard,
        private readonly VariableResolver $resolver,
        private readonly AuditLogger $auditLogger,
        private readonly Http $http,
    ) {}

    public function supports(): FlowNodeType
    {
        return FlowNodeType::Webhook;
    }

    public function execute(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->node->config ?? [];
        $url = (string) ($config['url'] ?? '');
        $method = strtoupper((string) ($config['method'] ?? 'POST'));

        $this->guard->assertAllowed($url);

        $headers = $this->resolveValues(
            is_array($config['headers'] ?? null) ? $config['headers'] : [],
            $context,
        );
        $payload = $this->resolveValues(
            is_array($config['payload'] ?? null) ? $config['payload'] : [],
            $context,
        );

        $request = $this->http
            ->timeout(5)
            ->connectTimeout(2)
            ->withoutRedirecting()
            ->withHeaders($headers);

        $response = match ($method) {
            'GET' => $request->get($url, $payload),
            'PUT' => $request->put($url, $payload),
            'PATCH' => $request->patch($url, $payload),
            'DELETE' => $request->delete($url, $payload),
            default => $request->post($url, $payload),
        };

        if ($response->failed()) {
            throw new FlowWebhookRequestFailedException(
                "El webhook '".WebhookUrlGuard::sanitizeForLog($url)."' respondió con estado {$response->status()}.",
            );
        }

        $this->auditLogger->record(
            action: 'flow.webhook_called',
            data: [
                'url' => WebhookUrlGuard::sanitizeForLog($url),
                'method' => $method,
                'status' => $response->status(),
            ],
            subjectType: FlowExecution::class,
            subjectId: $context->execution->id,
            tenantId: $context->tenant->id,
        );

        return NodeExecutionResult::continue();
    }

    /**
     * Resuelve variables `{{...}}` en valores string del payload/headers,
     * recursivamente.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function resolveValues(array $values, NodeExecutionContext $context): array
    {
        $resolved = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $resolved[$key] = $this->resolveValues($value, $context);

                continue;
            }

            $resolved[$key] = is_string($value)
                ? $this->resolver->resolve($value, $context->contact, $context->business, $context->conversation, $context->custom)
                : $value;
        }

        return $resolved;
    }
}
