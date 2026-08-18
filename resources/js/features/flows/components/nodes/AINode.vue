<script setup lang="ts">
import FlowNodeBase from './FlowNodeBase.vue';
import type { FlowEditorNodeData } from '../../flowEditorTypes';

defineProps<{ data: FlowEditorNodeData; selected?: boolean }>();
</script>

<template>
    <FlowNodeBase :data="data" :selected="selected" />
</template>
