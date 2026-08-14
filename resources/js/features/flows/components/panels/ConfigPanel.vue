<script setup lang="ts">
import { computed } from 'vue';
import type { FlowEditorNodeData } from '../../flowEditorTypes';
import MessageNodeConfig from './config/MessageNodeConfig.vue';
import ButtonsNodeConfig from './config/ButtonsNodeConfig.vue';
import QuestionNodeConfig from './config/QuestionNodeConfig.vue';
import ConditionNodeConfig from './config/ConditionNodeConfig.vue';
import DelayNodeConfig from './config/DelayNodeConfig.vue';
import TagNodeConfig from './config/TagNodeConfig.vue';
import WebhookNodeConfig from './config/WebhookNodeConfig.vue';
import HumanNodeConfig from './config/HumanNodeConfig.vue';
import EndNodeConfig from './config/EndNodeConfig.vue';

const props = defineProps<{ data: FlowEditorNodeData }>();

const emit = defineEmits<{ (e: 'update', value: Record<string, unknown>): void }>();

const config = computed({
    get: () => props.data.config ?? {},
    set: (value: Record<string, unknown>) => emit('update', value),
});
</script>

<template>
    <MessageNodeConfig v-if="data.type === 'message'" v-model="config" />
    <ButtonsNodeConfig v-else-if="data.type === 'buttons'" v-model="config" />
    <QuestionNodeConfig v-else-if="data.type === 'question'" v-model="config" />
    <ConditionNodeConfig v-else-if="data.type === 'condition'" v-model="config" />
    <DelayNodeConfig v-else-if="data.type === 'delay'" v-model="config" />
    <TagNodeConfig v-else-if="data.type === 'tag'" v-model="config" />
    <WebhookNodeConfig v-else-if="data.type === 'webhook'" v-model="config" />
    <HumanNodeConfig v-else-if="data.type === 'human'" v-model="config" />
    <EndNodeConfig v-else-if="data.type === 'end'" v-model="config" />
    <p v-else class="text-xs text-zinc-500">Este tipo de nodo no tiene configuración editable.</p>
</template>
