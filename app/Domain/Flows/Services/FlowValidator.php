<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Flows\Enums\FlowConditionOperator;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Models\FlowConnection;
use App\Domain\Flows\Models\FlowNode;

/**
 * Valida un flujo antes de publicarlo (FASE 11, `docs/chatbot-engine.md` §8).
 *
 * `validate()` devuelve la lista de errores de dominio (vacía = flujo válido).
 * Un flujo inválido jamás se publica (`FLOW_INVALID`).
 *
 * Reglas:
 * 1. Exactamente un nodo `start`.
 * 2. Config válida por tipo de nodo (nodo `ai` bloqueado: FASE 16).
 * 3. Determinismo: cada arista saliente con label solo en `condition`
 *    (exactamente una `true` y una `false`); el resto de nodos NO terminales
 *    tienen exactamente una arista saliente sin label.
 * 4. `end`/`human` terminales: sin aristas salientes.
 * 5. Todos los nodos alcanzables desde `start`; al menos un `end` alcanzable.
 * 6. Sin ciclos infinitos entre nodos síncronos (message/condition/tag/webhook/
 *    end); los nodos waiting (`question`/`buttons`/`human`) y `delay` rompen el
 *    ciclo.
 * 7. `config` del flujo (`max_steps`) válido si se declara.
 */
final class FlowValidator
{
    /**
     * @param  iterable<FlowNode>  $nodes
     * @param  iterable<FlowConnection>  $connections
     * @param  array<string, mixed>|null  $flowConfig
     * @return list<string>
     */
    public function validate(iterable $nodes, iterable $connections, ?array $flowConfig = null): array
    {
        $errors = [];
        $nodeMap = [];
        $starts = [];

        foreach ($nodes as $node) {
            $nodeMap[$node->id] = $node;

            if ($node->is_start) {
                $starts[] = $node;
            }

            $this->validateNodeConfig($node, $errors);
        }

        if (count($starts) !== 1) {
            $errors[] = 'El flujo debe tener exactamente un nodo de inicio.';
        }

        $outgoing = [];
        foreach ($connections as $connection) {
            $outgoing[$connection->source_node_id][] = [
                'target' => $connection->target_node_id,
                'label' => $connection->label,
            ];

            if (! isset($nodeMap[$connection->source_node_id])) {
                $errors[] = "La conexión desde el nodo \"{$connection->source_node_id}\" apunta a un nodo inexistente.";
            }

            if (! isset($nodeMap[$connection->target_node_id])) {
                $errors[] = "La conexión hacia el nodo \"{$connection->target_node_id}\" apunta a un nodo inexistente.";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $start = $starts[0];

        foreach ($nodeMap as $node) {
            $edges = $outgoing[$node->id] ?? [];

            if ($node->type === FlowNodeType::End || $node->type === FlowNodeType::Human) {
                if ($edges !== []) {
                    $errors[] = "El nodo \"{$node->name}\" es terminal y no debe tener conexiones salientes.";
                }

                continue;
            }

            if ($node->type === FlowNodeType::Condition) {
                $trueCount = count(array_filter($edges, static fn (array $edge): bool => $edge['label'] === 'true'));
                $falseCount = count(array_filter($edges, static fn (array $edge): bool => $edge['label'] === 'false'));

                if ($trueCount !== 1 || $falseCount !== 1) {
                    $errors[] = "El nodo \"{$node->name}\" (condición) debe tener exactamente una conexión 'true' y una 'false'.";
                }

                continue;
            }

            $unlabeledCount = count(array_filter($edges, static fn (array $edge): bool => $edge['label'] === null || $edge['label'] === ''));

            if (count($edges) !== 1 || $unlabeledCount !== 1) {
                $errors[] = "El nodo \"{$node->name}\" debe tener exactamente una conexión saliente sin label.";
            }
        }

        $reachable = $this->reachableFrom($start->id, $outgoing);

        foreach ($nodeMap as $node) {
            if (! isset($reachable[$node->id])) {
                $errors[] = "El nodo \"{$node->name}\" no es alcanzable desde el inicio.";
            }
        }

        $endReachable = false;
        foreach ($nodeMap as $node) {
            if ($node->type === FlowNodeType::End && isset($reachable[$node->id])) {
                $endReachable = true;
                break;
            }
        }

        if (! $endReachable) {
            $errors[] = 'El flujo debe tener al menos un nodo "end" alcanzable desde el inicio.';
        }

        $cycle = $this->detectSynchronousCycle($nodeMap, $outgoing);
        if ($cycle !== null) {
            $errors[] = "El flujo contiene un ciclo sin espera (bucle infinito) que pasa por: {$cycle}.";
        }

        if ($flowConfig !== null && array_key_exists('max_steps', $flowConfig)) {
            $maxSteps = $flowConfig['max_steps'];
            if (! is_int($maxSteps) || $maxSteps < 1) {
                $errors[] = 'max_steps debe ser un entero positivo.';
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateNodeConfig(FlowNode $node, array &$errors): void
    {
        $config = is_array($node->config) ? $node->config : [];
        $name = $node->name;

        switch ($node->type) {
            case FlowNodeType::Message:
                if (! $this->isNonEmptyString($config['text'] ?? null)) {
                    $errors[] = "El nodo \"{$name}\" (mensaje) requiere 'text' no vacío.";
                }
                break;

            case FlowNodeType::Buttons:
                if (! $this->isNonEmptyString($config['text'] ?? null)) {
                    $errors[] = "El nodo \"{$name}\" (botones) requiere 'text' no vacío.";
                }
                $this->validateButtons($config['buttons'] ?? null, $name, $errors);
                break;

            case FlowNodeType::Question:
                if (! $this->isNonEmptyString($config['prompt'] ?? null)) {
                    $errors[] = "El nodo \"{$name}\" (pregunta) requiere 'prompt' no vacío.";
                }
                if (! isset($config['field']) || ! is_string($config['field']) || preg_match('/^[a-z][a-z0-9_]*$/i', $config['field']) !== 1) {
                    $errors[] = "El nodo \"{$name}\" (pregunta) requiere 'field' con nombre de variable válido.";
                }
                break;

            case FlowNodeType::Condition:
                $this->validateConditionRules($config['rules'] ?? null, $name, $errors);
                break;

            case FlowNodeType::Delay:
                $seconds = $config['seconds'] ?? null;
                if (! is_int($seconds) || $seconds < 1 || $seconds > 3600) {
                    $errors[] = "El nodo \"{$name}\" (espera) requiere 'seconds' entero entre 1 y 3600.";
                }
                break;

            case FlowNodeType::Tag:
                $this->validateTags($config['tags'] ?? null, $name, $errors);
                break;

            case FlowNodeType::Webhook:
                $this->validateWebhook($config, $name, $errors);
                break;

            case FlowNodeType::AI:
                $errors[] = "El nodo \"{$name}\" es de tipo 'ai': no disponible en esta versión (reservado para FASE 16).";
                break;

            case FlowNodeType::Human:
                if (array_key_exists('handoff_message', $config) && ! $this->isNonEmptyString($config['handoff_message'])) {
                    $errors[] = "El nodo \"{$name}\" (transferir a humano) tiene un 'handoff_message' inválido.";
                }
                break;

            case FlowNodeType::End:
                break;
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateButtons(mixed $buttons, string $name, array &$errors): void
    {
        if (! is_array($buttons) || $buttons === [] || count($buttons) > 3) {
            $errors[] = "El nodo \"{$name}\" (botones) requiere entre 1 y 3 botones.";

            return;
        }

        $ids = [];
        foreach ($buttons as $button) {
            if (! is_array($button) || ! $this->isNonEmptyString($button['id'] ?? null) || ! $this->isNonEmptyString($button['title'] ?? null)) {
                $errors[] = "El nodo \"{$name}\" (botones) tiene un botón sin 'id' y 'title' válidos.";

                return;
            }

            $ids[] = $button['id'];
        }

        if (count(array_unique($ids)) !== count($ids)) {
            $errors[] = "El nodo \"{$name}\" (botones) tiene ids de botón duplicados.";
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateConditionRules(mixed $rules, string $name, array &$errors): void
    {
        if (! is_array($rules) || $rules === []) {
            $errors[] = "El nodo \"{$name}\" (condición) requiere al menos una regla.";

            return;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                $errors[] = "El nodo \"{$name}\" (condición) tiene una regla mal formada.";

                continue;
            }

            $field = $rule['field'] ?? null;
            $operator = FlowConditionOperator::tryFrom((string) ($rule['operator'] ?? ''));

            if (! $this->isNonEmptyString($field)) {
                $errors[] = "El nodo \"{$name}\" (condición) tiene una regla sin 'field'.";

                continue;
            }

            if ($operator === null) {
                $errors[] = "El nodo \"{$name}\" (condición) tiene una regla con operador desconocido.";

                continue;
            }

            if ($operator->needsValue() && ! array_key_exists('value', $rule)) {
                $errors[] = "El nodo \"{$name}\" (condición) tiene una regla sin 'value' para el operador '{$operator->value}'.";
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateTags(mixed $tags, string $name, array &$errors): void
    {
        if (! is_array($tags) || $tags === [] || count($tags) > 10) {
            $errors[] = "El nodo \"{$name}\" (etiquetar) requiere entre 1 y 10 etiquetas.";

            return;
        }

        foreach ($tags as $tag) {
            if (! $this->isNonEmptyString($tag)) {
                $errors[] = "El nodo \"{$name}\" (etiquetar) tiene una etiqueta vacía.";

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $errors
     */
    private function validateWebhook(array $config, string $name, array &$errors): void
    {
        $url = $config['url'] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors[] = "El nodo \"{$name}\" (webhook) requiere 'url' válida.";

            return;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $errors[] = "El nodo \"{$name}\" (webhook) requiere una 'url' http(s).";
        }

        $method = strtoupper((string) ($config['method'] ?? 'POST'));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $errors[] = "El nodo \"{$name}\" (webhook) requiere un 'method' HTTP válido.";
        }
    }

    /**
     * @param  array<string, array<int, array{target: string, label: string|null}>>  $outgoing
     * @return array<string, true>
     */
    private function reachableFrom(string $startId, array $outgoing): array
    {
        $visited = [];
        $queue = [$startId];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            foreach ($outgoing[$current] ?? [] as $edge) {
                if (! isset($visited[$edge['target']])) {
                    $queue[] = $edge['target'];
                }
            }
        }

        return $visited;
    }

    /**
     * Detecta ciclos en el subgrafo síncrono: nodos que se ejecutan en el mismo
     * `handleMessage` (message/condition/tag/webhook/end). Los nodos waiting
     * (`question`/`buttons`/`human`) y `delay` rompen el ciclo (el motor espera
     * input o agenda la continuación).
     *
     * @param  array<string, FlowNode>  $nodeMap
     * @param  array<string, array<int, array{target: string, label: string|null}>>  $outgoing
     */
    private function detectSynchronousCycle(array $nodeMap, array $outgoing): ?string
    {
        $syncIds = [];
        foreach ($nodeMap as $node) {
            if (! $node->type->isWaitingType() && $node->type !== FlowNodeType::Delay) {
                $syncIds[$node->id] = true;
            }
        }

        $graph = [];
        foreach ($syncIds as $id => $_) {
            $graph[$id] = [];
            foreach ($outgoing[$id] ?? [] as $edge) {
                if (isset($syncIds[$edge['target']])) {
                    $graph[$id][] = $edge['target'];
                }
            }
        }

        $white = [];   // sin visitar
        $grey = [];    // en pila DFS
        $black = [];   // terminado
        $parent = [];

        foreach ($graph as $id => $_) {
            $white[$id] = true;
        }

        $cycleStart = null;
        foreach ($graph as $id => $_) {
            if (! isset($white[$id])) {
                continue;
            }

            $stack = [$id];
            $parent[$id] = null;

            while ($stack !== []) {
                $current = end($stack);
                unset($white[$current]);
                $grey[$current] = true;

                $advanced = false;
                foreach ($graph[$current] as $next) {
                    if (isset($grey[$next])) {
                        $cycleStart = $next;
                        break 2;
                    }
                    if (isset($white[$next])) {
                        $parent[$next] = $current;
                        $stack[] = $next;
                        $advanced = true;
                        break;
                    }
                }

                if ($advanced) {
                    continue;
                }

                unset($grey[$current]);
                $black[$current] = true;
                array_pop($stack);
            }

            if ($cycleStart !== null) {
                break;
            }
        }

        if ($cycleStart === null) {
            return null;
        }

        $names = [];
        $current = $cycleStart;
        $seen = [];
        do {
            if (isset($seen[$current])) {
                break;
            }
            $seen[$current] = true;
            $node = $nodeMap[$current];
            $names[] = $node->name;
            $current = $parent[$current];
        } while ($current !== null && $current !== $cycleStart);

        return implode(' → ', array_slice($names, 0, 5)).(count($names) > 5 ? '…' : '');
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
