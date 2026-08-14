<script setup lang="ts">
defineProps<{
    title: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
}>();

const emit = defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>();
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <h3 class="text-base font-semibold text-zinc-900">{{ title }}</h3>
            <p class="mt-2 text-sm text-zinc-600">{{ message }}</p>

            <div class="mt-5 flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
                    @click="emit('cancel')"
                >
                    {{ cancelLabel ?? 'Cancelar' }}
                </button>
                <button
                    type="button"
                    class="rounded-md px-4 py-2 text-sm font-semibold text-white hover:opacity-90"
                    :class="danger ? 'bg-red-600' : 'bg-zinc-900'"
                    @click="emit('confirm')"
                >
                    {{ confirmLabel ?? 'Confirmar' }}
                </button>
            </div>
        </div>
    </div>
</template>
