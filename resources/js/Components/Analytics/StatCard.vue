<script setup lang="ts">
defineProps<{
  label: string;
  value: string;
  subtitle?: string;
}>();
</script>

<template>
  <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
    <p class="text-sm font-medium text-zinc-500">{{ label }}</p>
    <p class="mt-2 text-2xl font-bold text-zinc-900">{{ value }}</p>
    <p v-if="subtitle" class="mt-1 text-xs text-zinc-400">{{ subtitle }}</p>
  </div>
</template>
