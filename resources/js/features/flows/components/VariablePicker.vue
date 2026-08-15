<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { VariableDefinition } from '../flowTypes';
import { NAMESPACE_LABELS, useVariableCatalog } from '../useVariableCatalog';

/**
 * Selector de variables del catálogo del flujo (FASE 13, UNIDAD 4).
 *
 * Carga el catálogo (definiciones derivadas server-side) de forma perezosa y
 * emite la variable elegida; el insertar `{{clave}}` lo decide el componente
 * padre (campo/cursor). Nunca resuelve valores runtime ni usa `v-html`.
 */
const props = defineProps<{ tenantId: string; flowId: string; disabled?: boolean }>();

const emit = defineEmits<{ (e: 'select', variable: VariableDefinition): void }>();

const open = ref(false);
const root = ref<HTMLDivElement | null>(null);

const catalog = useVariableCatalog({ tenantId: props.tenantId, flowId: props.flowId });

onMounted(() => {
    void catalog.load();
    document.addEventListener('click', handleOutsideClick);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', handleOutsideClick);
});

function toggle(): void {
    if (props.disabled) {
        return;
    }

    open.value = !open.value;
    if (open.value) {
        void catalog.load();
    }
}

function handleOutsideClick(event: Event): void {
    if (open.value && root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

function pick(variable: VariableDefinition): void {
    emit('select', variable);
    open.value = false;
}
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="inline-flex items-center gap-1 rounded-md border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 disabled:opacity-40 disabled:hover:bg-transparent"
            :disabled="disabled"
            :aria-expanded="open"
            @click="toggle"
        >
            <span aria-hidden="true">{ }</span>
            {{ open ? 'Cerrar variables' : 'Insertar variable' }}
        </button>

        <div
            v-if="open"
            class="absolute left-0 top-full z-30 mt-1 w-72 rounded-lg border border-zinc-200 bg-white p-2 shadow-lg"
        >
            <input
                v-model="catalog.search.value"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-xs focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Buscar variable..."
            />

            <div class="mt-2 max-h-56 overflow-y-auto">
                <p v-if="catalog.loading.value" class="px-2 py-3 text-center text-xs text-zinc-400">
                    Cargando variables...
                </p>

                <p v-else-if="catalog.error.value" class="px-2 py-3 text-center text-xs text-red-600">
                    {{ catalog.error.value }}
                </p>

                <template v-else>
                    <p v-if="catalog.groups.value.length === 0" class="px-2 py-3 text-center text-xs text-zinc-400">
                        Sin variables para este flujo.
                    </p>

                    <div v-for="group in catalog.groups.value" :key="group.namespace" class="mb-2 last:mb-0">
                        <p class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wide text-zinc-400">
                            {{ NAMESPACE_LABELS[group.namespace] }}
                        </p>
                        <button
                            v-for="variable in group.items"
                            :key="variable.key"
                            type="button"
                            class="block w-full rounded-md px-2 py-1.5 text-left hover:bg-emerald-50"
                            @click="pick(variable)"
                        >
                            <span class="block text-xs font-mono text-zinc-800">{{ variable.key }}</span>
                            <span class="block truncate text-[11px] text-zinc-400">{{ variable.label }}</span>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
