<script setup lang="ts">
import { computed } from 'vue';
import type { FlowEditorController } from '../../useFlowEditor';

const props = defineProps<{ editor: FlowEditorController }>();

const edge = computed(() => {
    if (props.editor.selected.value?.kind !== 'edge') {
        return null;
    }

    return props.editor.edges.value.find((e) => e.id === props.editor.selected.value?.id) ?? null;
});

const edgeId = computed(() => edge.value?.id ?? null);

const sourceNode = computed(() =>
    edge.value ? props.editor.nodes.value.find((n) => n.id === edge.value?.source) ?? null : null,
);

const targetNode = computed(() =>
    edge.value ? props.editor.nodes.value.find((n) => n.id === edge.value?.target) ?? null : null,
);

const label = computed(() => edge.value?.label ?? '');

function updateLabel(value: string): void {
    if (edgeId.value) {
        props.editor.updateEdgeLabel(edgeId.value, value);
    }
}

function remove(): void {
    if (edgeId.value) {
        props.editor.removeEdge(edgeId.value);
    }
}
</script>

<template>
    <div v-if="edge" class="space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-zinc-900">Conexión</h3>
            <p class="mt-0.5 truncate text-xs text-zinc-500">
                {{ sourceNode?.data.name ?? edge.source }} → {{ targetNode?.data.name ?? edge.target }}
            </p>
        </div>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Rama / etiqueta</span>
            <input
                :value="label"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                :disabled="editor.readOnly.value"
                placeholder="true / false / otra etiqueta"
                @input="updateLabel(($event.target as HTMLInputElement).value)"
            />
        </label>

        <button
            v-if="!editor.readOnly.value"
            type="button"
            class="rounded-md border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
            @click="remove"
        >
            Eliminar conexión
        </button>
    </div>

    <div v-else class="py-8 text-center text-xs text-zinc-400">
        Seleccioná una conexión en el canvas para editarla.
    </div>
</template>
