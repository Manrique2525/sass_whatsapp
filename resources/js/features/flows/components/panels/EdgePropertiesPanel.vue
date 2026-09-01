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
const isConditionEdge = computed(() => sourceNode.value?.data.type === 'condition');
const conditionBranch = computed(() => {
    if (!isConditionEdge.value) {
        return null;
    }

    return edge.value?.sourceHandle === 'true' || edge.value?.sourceHandle === 'false'
        ? edge.value.sourceHandle
        : null;
});
const hasInvalidNormalLabel = computed(() => !isConditionEdge.value && label.value !== '');

function clearInvalidLabel(): void {
    if (edgeId.value) {
        props.editor.updateEdgeLabel(edgeId.value, '');
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

        <div v-if="isConditionEdge" class="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2">
            <span class="block text-xs font-medium text-zinc-600">Rama</span>
            <span v-if="conditionBranch" class="mt-1 block text-sm font-semibold text-zinc-900">{{ conditionBranch }}</span>
            <span v-else class="mt-1 block text-xs text-red-600">Rama inválida o desconocida.</span>
        </div>

        <div v-else-if="hasInvalidNormalLabel" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
            <span class="block text-xs font-medium text-amber-800">Esta conexión no admite etiquetas.</span>
            <button
                v-if="!editor.readOnly.value"
                type="button"
                class="mt-2 rounded-md border border-amber-300 px-2.5 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100"
                @click="clearInvalidLabel"
            >
                Quitar etiqueta
            </button>
        </div>

        <p v-else class="text-xs text-zinc-500">Salida determinista sin etiqueta.</p>

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
