<script setup lang="ts">
defineProps<{
  label: string;
  value: string;
  subtitle?: string;
}>();
</script>

<template>
  <div class="app-card p-6 transition hover:-translate-y-0.5 hover:shadow-[0_16px_38px_rgba(16,38,31,0.1)]">
    <p class="text-sm font-medium text-[#71877b]">{{ label }}</p>
    <p class="mt-2 text-2xl font-bold tracking-[-0.03em] text-[#10261f]">{{ value }}</p>
    <p v-if="subtitle" class="mt-1 text-xs text-[#8a9b91]">{{ subtitle }}</p>
  </div>
</template>
