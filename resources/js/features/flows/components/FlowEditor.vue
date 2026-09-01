<script setup lang="ts">
import { computed, watch } from 'vue';
import { useVueFlow, VueFlow } from '@vue-flow/core';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import type { GraphEdge, GraphNode, NodeTypesObject, EdgeTypesObject } from '@vue-flow/core';
import { Background, BackgroundVariant } from '@vue-flow/background';
import { MiniMap } from '@vue-flow/minimap';
import { Controls } from '@vue-flow/controls';
import type { FlowEditorController } from '../useFlowEditor';
import { flowNodeTypes } from './nodes';
import FlowEdge from './edges/FlowEdge.vue';
import NodePalette from './NodePalette.vue';
import EmptyState from './EmptyState.vue';
import FlowToolbar from './FlowToolbar.vue';
import ValidationPanel from './panels/ValidationPanel.vue';
import NodePropertiesPanel from './panels/NodePropertiesPanel.vue';
import EdgePropertiesPanel from './panels/EdgePropertiesPanel.vue';
import FlowPropertiesPanel from './panels/FlowPropertiesPanel.vue';

const props = defineProps<{ editor: FlowEditorController }>();

const emit = defineEmits<{ (e: 'deactivate-request'): void }>();

const { findNode, setCenter } = useVueFlow();

const nodeTypes = flowNodeTypes as unknown as NodeTypesObject;
const edgeTypes = { default: FlowEdge } as unknown as EdgeTypesObject;

const vfNodes = computed(() => props.editor.nodes.value as unknown as GraphNode[]);
const vfEdges = computed(() => props.editor.edges.value as unknown as GraphEdge[]);

watch(
    () => props.editor.centerRequest.value,
    (request) => {
        if (!request) {
            return;
        }

        const node = findNode(request.nodeId);
        if (!node) {
            return;
        }

        const x = node.position.x + (node.dimensions?.width ?? 0) / 2;
        const y = node.position.y + (node.dimensions?.height ?? 0) / 2;
        setCenter(x, y, { zoom: 1, duration: 300 });
    },
);
</script>

<template>
    <div class="flex min-h-[calc(100vh-220px)] flex-col gap-3">
        <FlowToolbar :editor="editor" @deactivate-request="emit('deactivate-request')" />

        <div v-if="editor.connectError.value" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700">
            {{ editor.connectError.value }}
        </div>

        <div v-if="editor.readOnly.value" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-700">
            Flujo publicado: el editor está en modo solo lectura. Desactivá el flujo para poder editarlo.
        </div>

        <div v-if="editor.error.value" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700">
            {{ editor.error.value }}
        </div>

        <div class="grid flex-1 grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_21rem]">
            <div class="relative h-[560px] min-w-0 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                <div class="absolute left-3 top-3 z-20">
                    <NodePalette :editor="editor" />
                </div>

                <VueFlow
                    :nodes="vfNodes"
                    :edges="vfEdges"
                    :node-types="nodeTypes"
                    :edge-types="edgeTypes"
                    :nodes-draggable="!editor.readOnly.value"
                    :nodes-connectable="!editor.readOnly.value"
                    :elements-selectable="true"
                    :fit-view-on-init="true"
                    :min-zoom="0.25"
                    :max-zoom="2"
                    @nodes-change="editor.onNodesChange"
                    @edges-change="editor.onEdgesChange"
                    @connect="editor.onConnect"
                    @node-drag-stop="editor.onNodeDragStop"
                >
                    <Background :variant="BackgroundVariant.Dots" :gap="20" :size="1.5" />
                    <MiniMap pannable zoomable />
                    <Controls position="bottom-left" />
                </VueFlow>

                <EmptyState v-if="editor.empty.value" :editor="editor" />
            </div>

            <aside class="min-w-0 overflow-y-auto rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
                <NodePropertiesPanel
                    v-if="editor.selected.value?.kind === 'node'"
                    :editor="editor"
                />
                <EdgePropertiesPanel
                    v-else-if="editor.selected.value?.kind === 'edge'"
                    :editor="editor"
                />
                <FlowPropertiesPanel v-else :editor="editor" />
            </aside>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Validación</h3>
            <ValidationPanel :editor="editor" />
        </div>
    </div>
</template>
