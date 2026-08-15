import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { ApiErrorPayload } from './flowEditorTypes';
import type { VariableDefinition, VariableNamespace } from './flowTypes';
import { getFlowVariables } from './flowApi';

export interface VariableCatalogContext {
    tenantId: string;
    flowId: string;
}

export interface VariableCatalogGroup {
    namespace: VariableNamespace;
    items: VariableDefinition[];
}

const NAMESPACE_LABELS: Record<VariableNamespace, string> = {
    contact: 'Contacto',
    business: 'Negocio',
    conversation: 'Conversación',
    custom: 'Personalizadas de este flujo',
};

const NAMESPACE_ORDER: VariableNamespace[] = ['contact', 'business', 'conversation', 'custom'];

/**
 * Catálogo de variables de un flujo (FASE 13, UNIDAD 4).
 *
 * El catálogo viene de `GET /flows/{flow}/variables` (definiciones derivadas
 * server-side; el backend es la autoridad, nunca se envían filtros). Se carga
 * de forma perezosa y cacheada por instancia. Los mapas de claves derivadas de
 * usuario se construyen con `Map` (o arrays) — NUNCA con objetos planos, para
 * evitar `__proto__`/`constructor`/`prototype` (prototype pollution).
 */
export function useVariableCatalog(context: VariableCatalogContext): {
    items: Ref<VariableDefinition[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    search: Ref<string>;
    load: () => Promise<void>;
    byKey: (key: string) => VariableDefinition | null;
    groups: ComputedRef<VariableCatalogGroup[]>;
} {
    const items = ref<VariableDefinition[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const search = ref('');

    let loaded = false;

    async function load(): Promise<void> {
        if (loaded) {
            return;
        }

        loading.value = true;
        error.value = null;

        try {
            items.value = await getFlowVariables(context.tenantId, context.flowId);
            loaded = true;
        } catch (err) {
            const apiError = err as ApiErrorPayload;
            error.value = apiError.message ?? 'No se pudo cargar el catálogo de variables.';
        } finally {
            loading.value = false;
        }
    }

    function byKey(key: string): VariableDefinition | null {
        return items.value.find((item) => item.key === key) ?? null;
    }

    const groups = computed<VariableCatalogGroup[]>(() => {
        const query = search.value.trim().toLowerCase();
        const matches = (item: VariableDefinition): boolean =>
            query === '' || item.key.toLowerCase().includes(query) || item.label.toLowerCase().includes(query);

        const byNamespace = new Map<VariableNamespace, VariableDefinition[]>();

        for (const item of items.value) {
            if (!matches(item)) {
                continue;
            }
            const list = byNamespace.get(item.namespace) ?? [];
            list.push(item);
            byNamespace.set(item.namespace, list);
        }

        return NAMESPACE_ORDER.filter((namespace) => byNamespace.has(namespace)).map((namespace) => ({
            namespace,
            items: byNamespace.get(namespace) ?? [],
        }));
    });

    return { items, loading, error, search, load, byKey, groups };
}

export { NAMESPACE_LABELS, NAMESPACE_ORDER };
