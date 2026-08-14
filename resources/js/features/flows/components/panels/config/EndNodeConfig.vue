<script setup lang="ts">
defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();
</script>

<template>
    <p class="text-xs text-zinc-500">Este nodo finaliza el flujo. No requiere configuración.</p>
</template>
