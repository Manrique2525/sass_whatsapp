import { ref, computed } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import type { KnowledgeBase } from './knowledgeTypes';
import { fetchKnowledgeBases } from './knowledgeApi';

/**
 * Composable para cargar knowledge bases del tenant (FASE 17 U3.5).
 *
 * Usado por AiNodeConfig para el selector de KB. Carga una sola vez
 * (lazy) y cachea el resultado.
 */
export function useKnowledgeBases(context: { tenantId: string }): {
    items: Ref<KnowledgeBase[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    load: () => Promise<void>;
    byId: (id: string) => KnowledgeBase | null;
    hasKBs: ComputedRef<boolean>;
} {
    const items = ref<KnowledgeBase[]>([]);
    const loading = ref(false);
    const error = ref<string | null>(null);

    let loaded = false;

    async function load(): Promise<void> {
        if (loaded) {
            return;
        }

        loading.value = true;
        error.value = null;

        try {
            const res = await fetchKnowledgeBases(context.tenantId, { per_page: 100 });
            items.value = res.knowledge_bases;
            loaded = true;
        } catch (err) {
            const apiErr = err as { message?: string };
            error.value = apiErr.message ?? 'No se pudieron cargar las bases de conocimiento.';
        } finally {
            loading.value = false;
        }
    }

    function byId(id: string): KnowledgeBase | null {
        return items.value.find((kb) => kb.id === id) ?? null;
    }

    const hasKBs = computed(() => items.value.length > 0);

    return { items, loading, error, load, byId, hasKBs };
}
