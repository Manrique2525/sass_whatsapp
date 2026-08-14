<script setup lang="ts">
import { computed } from 'vue';
import type { FlowEditorController } from '../../useFlowEditor';
import { nodeTypeLabel } from '../../flowUtils';
import { canNodeBeStart } from '../../flowAdapter';
import ConfigPanel from './ConfigPanel.vue';

const props = defineProps<{ editor: FlowEditorController }>();

const nodeId = computed(() => (props.editor.selected.value?.kind === 'node' ? props.editor.selected.value.id : null));
const node = computed(() => props.editor.nodes.value.find((n) => n.id === nodeId.value) ?? null);

const currentId = computed(() => node.value?.id ?? null);

function updateConfig(value: Record<string, unknown>): void {
    if (currentId.value) {
        props.editor.updateNodeConfig(currentId.value, value);
    }
}

function updateName(event: Event): void {
    if (currentId.value) {
        props.editor.updateNodeName(currentId.value, (event.target as HTMLInputElement).value);
    }
}

function onStartToggle(event: Event): void {
    if (!currentId.value) {
        return;
    }

    if ((event.target as HTMLInputElement).checked) {
        props.editor.setStartNode(currentId.value);
    } else {
        props.editor.clearStartNode(currentId.value);
    }
}
</script>

<template>
    <div v-if="node" class="space-y-4">
        <div>
            <h3 class="text-sm font-semibold text-zinc-900">Nodo: {{ nodeTypeLabel(node.data.type) }}</h3>
            <p class="mt-0.5 text-xs text-zinc-500">Configuración del nodo seleccionado.</p>
        </div>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-zinc-600">Nombre</span>
            <input
                :value="node.data.name"
                type="text"
                class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                :disabled="editor.readOnly.value"
                @change="updateName"
            />
        </label>

        <label v-if="canNodeBeStart(node.data.type)" class="flex items-center justify-between gap-2">
            <span class="text-xs font-medium text-zinc-600">Nodo de inicio</span>
            <input
                type="checkbox"
                class="h-4 w-4 rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500"
                :checked="node.data.isStart"
                :disabled="editor.readOnly.value"
                @change="onStartToggle"
            />
        </label>

        <div class="border-t border-zinc-200 pt-4">
            <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Configuración</h4>
            <ConfigPanel :data="node.data" @update="updateConfig" />
        </div>

        <div v-if="!editor.readOnly.value" class="flex gap-2 border-t border-zinc-200 pt-4">
            <button
                type="button"
                class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50"
                @click="editor.duplicateNode(node.id)"
            >
                Duplicar
            </button>
            <button
                type="button"
                class="rounded-md border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                @click="editor.removeNode(node.id)"
            >
                Eliminar
            </button>
        </div>
    </div>

    <div v-else class="py-8 text-center text-xs text-zinc-400">
        Seleccioná un nodo en el canvas para editar su configuración.
    </div>
</template>
