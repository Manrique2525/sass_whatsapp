<script setup lang="ts">
import { computed } from 'vue';
import { BaseEdge, EdgeLabelRenderer, getBezierPath } from '@vue-flow/core';
import type { EdgeProps } from '@vue-flow/core';

const props = defineProps<EdgeProps>();

const bezierPath = computed(() => getBezierPath({
    sourceX: props.sourceX,
    sourceY: props.sourceY,
    targetX: props.targetX,
    targetY: props.targetY,
    sourcePosition: props.sourcePosition,
    targetPosition: props.targetPosition,
}));

const path = computed(() => bezierPath.value[0]);
const labelX = computed(() => bezierPath.value[1]);
const labelY = computed(() => bezierPath.value[2]);

const label = computed(() => (props.label ? String(props.label) : ''));
</script>

<template>
    <BaseEdge :id="id" :path="path" :style="style" :marker-end="markerEnd" />

    <EdgeLabelRenderer>
        <span
            v-if="label !== ''"
            class="pointer-events-none absolute rounded border border-zinc-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-zinc-600 shadow-sm"
            :style="{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)` }"
        >
            {{ label }}
        </span>
    </EdgeLabelRenderer>
</template>
