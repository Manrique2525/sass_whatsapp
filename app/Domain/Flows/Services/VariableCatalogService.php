<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Flows\Enums\FlowNodeType;
use App\Domain\Flows\Enums\VariableType;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\ValueObjects\VariableDefinition;

/**
 * Catálogo de variables de un flujo (FASE 13, UNIDAD 1).
 *
 * El catálogo es DERIVADO: se construye a partir de los datos del flujo y del
 * tenant, no se persiste en ninguna tabla (ADR-046). Namespaces:
 * - `contact`: name/email/phone (+ `metadata.*` no enumerable: infinito).
 * - `business`: solo `BusinessProfile::PUBLIC_FIELDS` (única whitelist que
 *   también usa `VariableResolver`; nunca expone secretos).
 * - `conversation`: solo `id` (solo lectura).
 * - `custom`: variables capturadas por nodos `question` (claves normalizadas
 *   y validadas, tipo/default declarados en la config del nodo).
 *
 * Respeta el multi-tenancy: opera sobre el `Flow` ya acotado al tenant por
 * `TenantContext`/scope global; nunca consulta datos de otro tenant.
 */
final class VariableCatalogService
{
    /**
     * @return list<VariableDefinition>
     */
    public function forFlow(Flow $flow): array
    {
        return [
            ...$this->contactVariables(),
            ...$this->businessVariables(),
            ...$this->conversationVariables(),
            ...$this->customVariables($flow),
        ];
    }

    /**
     * @return list<VariableDefinition>
     */
    private function contactVariables(): array
    {
        return [
            new VariableDefinition('contact.name', 'Nombre', 'contact', 'contact.name', VariableType::String, null, false),
            new VariableDefinition('contact.email', 'Email', 'contact', 'contact.email', VariableType::String, null, false),
            new VariableDefinition('contact.phone', 'Teléfono', 'contact', 'contact.phone', VariableType::String, null, false),
        ];
    }

    /**
     * @return list<VariableDefinition>
     */
    private function businessVariables(): array
    {
        $labels = [
            'name' => 'Nombre del negocio',
            'description' => 'Descripción',
            'category' => 'Categoría',
            'address' => 'Dirección',
            'website' => 'Sitio web',
            'email' => 'Email del negocio',
            'phone' => 'Teléfono del negocio',
        ];

        $definitions = [];

        foreach (BusinessProfile::PUBLIC_FIELDS as $field) {
            $definitions[] = new VariableDefinition(
                key: 'business.'.$field,
                label: $labels[$field],
                namespace: 'business',
                source: 'business.'.$field,
                type: VariableType::String,
                default: null,
                writable: false,
            );
        }

        return $definitions;
    }

    /**
     * @return list<VariableDefinition>
     */
    private function conversationVariables(): array
    {
        return [
            new VariableDefinition('conversation.id', 'ID de conversación', 'conversation', 'conversation.id', VariableType::String, null, false),
        ];
    }

    /**
     * @return list<VariableDefinition>
     */
    private function customVariables(Flow $flow): array
    {
        $definitions = [];
        $seen = [];

        foreach ($flow->nodes()->where('type', FlowNodeType::Question->value)->get() as $node) {
            $config = is_array($node->config) ? $node->config : [];
            $raw = (string) ($config['field'] ?? '');
            $key = VariableGuard::normalizeKey($raw);

            if (! VariableGuard::isValidKey($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $definitions[] = new VariableDefinition(
                key: 'custom.'.$key,
                label: $node->name,
                namespace: 'custom',
                source: 'question:'.$node->name,
                type: VariableType::tryFrom((string) ($config['type'] ?? '')) ?? VariableType::String,
                default: $config['default'] ?? null,
                writable: true,
            );
        }

        return $definitions;
    }
}
