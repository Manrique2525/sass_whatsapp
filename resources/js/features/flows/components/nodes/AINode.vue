<script setup lang="ts">
import FlowNodeBase from './FlowNodeBase.vue';
import type { FlowEditorNodeData } from '../../flowEditorTypes';

defineProps<{ data: FlowEditorNodeData; selected?: boolean }>();
</script>

<template>
    <div class="relative">
        <FlowNodeBase :data="data" :selected="selected" />
        <span class="absolute -bottom-3 left-1/2 w-max -translate-x-1/2 rounded bg-zinc-800 px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">
            FASE 16 · Reservado
        </span>
    </div>
</template>
