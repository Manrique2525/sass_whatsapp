<script setup lang="ts">
import { ref } from 'vue';

const props = defineProps<{
    sending: boolean;
    disabled: boolean;
}>();

const emit = defineEmits<{
    send: [body: string];
}>();

const text = ref('');

function submit(): void {
    const body = text.value.trim();

    if (body === '' || props.sending || props.disabled) {
        return;
    }

    emit('send', body);
    text.value = '';
}

function onEnter(event: KeyboardEvent): void {
    if (!event.shiftKey) {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <form class="flex items-end gap-2 border-t border-zinc-200 bg-white p-3" @submit.prevent="submit">
        <textarea
            v-model="text"
            rows="2"
            placeholder="Escribi un mensaje..."
            class="max-h-32 min-h-[42px] flex-1 resize-none rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-800"
            :disabled="props.disabled"
            @keydown="onEnter"
        />

        <button
            type="submit"
            class="h-10 w-10 shrink-0 rounded-full bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50"
            :disabled="props.sending || props.disabled || text.trim() === ''"
            aria-label="Enviar mensaje"
        >
            <svg class="mx-auto h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 2 11 13" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M22 2 15 22l-4-9-9-4z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </form>
</template>
