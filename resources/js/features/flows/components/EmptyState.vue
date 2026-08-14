<script setup lang="ts">
import type { FlowEditorController } from '../useFlowEditor';
import NodePalette from './NodePalette.vue';

defineProps<{ editor: FlowEditorController }>();
</script>

<template>
    <div class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
        <div class="pointer-events-auto max-w-sm rounded-xl border border-zinc-200 bg-white p-6 text-center shadow-lg">
            <p class="text-sm font-semibold text-zinc-900">Este flujo todavía no tiene nodos</p>
            <p class="mt-2 text-xs text-zinc-500">
                Agregá tu primer nodo desde el botón "Agregar nodo" para empezar a armar la automatización.
            </p>
            <div class="mt-4 flex justify-center">
                <NodePalette :editor="editor" />
            </div>
        </div>
    </div>
</template>
