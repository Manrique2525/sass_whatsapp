<script setup lang="ts">
import { computed } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { nodeConfigSummary } from '../../flowUtils';
import { configIssuesForNode } from '../../flowValidation';
import type { FlowEditorNodeData } from '../../flowEditorTypes';

const props = withDefaults(
    defineProps<{
        data: FlowEditorNodeData;
        selected?: boolean;
        showDefaultSource?: boolean;
    }>(),
    { selected: false, showDefaultSource: true },
);

const accent: Record<FlowEditorNodeData['type'], string> = {
    message: 'bg-sky-600',
    buttons: 'bg-violet-600',
    question: 'bg-amber-500',
    condition: 'bg-rose-600',
    delay: 'bg-teal-600',
    tag: 'bg-emerald-600',
    webhook: 'bg-indigo-600',
    ai: 'bg-zinc-500',
    human: 'bg-purple-600',
    end: 'bg-slate-700',
};

const headerClass = computed(() => accent[props.data.type] ?? 'bg-zinc-500');
const summary = computed(() => nodeConfigSummary(props.data.type, props.data.config));
const issues = computed(() => configIssuesForNode(props.data.type, props.data.config));
const terminal = computed(() => props.data.type === 'end' || props.data.type === 'human');
</script>

<template>
    <div
        class="relative w-56 rounded-lg border bg-white shadow-sm transition"
        :class="[
            selected ? 'border-emerald-500 ring-2 ring-emerald-200' : 'border-zinc-200 hover:border-zinc-300',
        ]"
    >
        <Handle type="target" :position="Position.Left" />

        <div class="flex items-center justify-between gap-2 rounded-t-lg px-3 py-1.5" :class="headerClass">
            <span class="text-[11px] font-semibold uppercase tracking-wide text-white">{{ data.typeLabel }}</span>
            <span v-if="data.isStart" class="rounded bg-white/90 px-1.5 py-0.5 text-[9px] font-bold uppercase text-emerald-700">
                Inicio
            </span>
        </div>

        <div class="relative px-3 py-2">
            <p class="text-sm font-medium text-zinc-900">{{ data.name }}</p>
            <p v-if="summary !== ''" class="mt-0.5 line-clamp-2 text-xs text-zinc-500">
                {{ summary }}
            </p>

            <span
                v-if="issues.length > 0"
                class="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-amber-500 text-[11px] font-bold text-white"
                :title="issues.join(' · ')"
            >
                !
            </span>
        </div>

        <Handle
            v-if="showDefaultSource && !terminal"
            type="source"
            :position="Position.Right"
        />
    </div>
</template>
