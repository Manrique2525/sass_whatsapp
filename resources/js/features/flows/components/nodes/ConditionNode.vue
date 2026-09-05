<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core';
import FlowNodeBase from './FlowNodeBase.vue';
import type { FlowEditorNodeData } from '../../flowEditorTypes';

defineProps<{ data: FlowEditorNodeData; selected?: boolean }>();
</script>

<template>
    <div class="relative">
        <FlowNodeBase :data="data" :selected="selected" :show-default-source="false" />

        <div class="flex items-center justify-between gap-1 px-2 pb-1 text-[9px] font-semibold uppercase tracking-wide">
            <span class="flex items-center gap-0.5 text-emerald-600">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500" />
                true
            </span>
            <span class="flex items-center gap-0.5 text-rose-600">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-rose-500" />
                false
            </span>
        </div>

        <Handle id="true" type="source" :position="Position.Right" class="!top-[38%] !right-[-6px] !bg-emerald-500" />
        <Handle id="false" type="source" :position="Position.Right" class="!top-[70%] !right-[-6px] !bg-rose-500" />
    </div>
</template>
