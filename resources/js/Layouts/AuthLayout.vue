<script setup lang="ts">
defineProps<{
    title?: string;
}>();
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-zinc-100 px-4">
        <div class="w-full max-w-md">
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold text-zinc-900">{{ title ?? 'WhatsApp SaaS' }}</h1>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <slot />
            </div>
        </div>
    </div>
</template>
