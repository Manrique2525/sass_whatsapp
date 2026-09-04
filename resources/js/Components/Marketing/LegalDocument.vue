<script setup lang="ts">
import MarketingLayout from '@/Layouts/MarketingLayout.vue';

defineProps<{
    title: string;
    description: string;
    eyebrow: string;
}>();
</script>

<template>
    <MarketingLayout :page-title="title" :page-description="description">
        <section class="pt-32 pb-20 sm:pt-40 sm:pb-28" aria-labelledby="legal-title">
            <article class="mx-auto max-w-3xl px-5 sm:px-8">
                <p class="eyebrow">{{ eyebrow }}</p>
                <h1 id="legal-title" class="mt-4 text-4xl font-semibold leading-tight tracking-[-0.05em] text-[#10261f] sm:text-6xl">{{ title }}</h1>
                <p class="mt-6 text-sm leading-6 text-[#64756d]">Última actualización: 4 de septiembre de 2026</p>
                <div class="legal-prose mt-12">
                    <slot />
                </div>
            </article>
        </section>
    </MarketingLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.eyebrow { @apply text-xs font-bold uppercase tracking-[0.18em] text-[#0b8f5a]; }
.legal-prose :deep(h2) { @apply mt-12 text-2xl font-semibold tracking-[-0.03em] text-[#10261f]; }
.legal-prose :deep(h2:first-child) { @apply mt-0; }
.legal-prose :deep(p) { @apply mt-4 text-base leading-8 text-[#52685c]; }
.legal-prose :deep(ul) { @apply mt-4 list-disc space-y-2 pl-6 text-base leading-8 text-[#52685c]; }
.legal-prose :deep(a) { @apply font-medium text-[#0b8f5a] underline decoration-[#b7f36b] decoration-2 underline-offset-2; }
.legal-prose :deep(.legal-note) { @apply mt-12 rounded-2xl border border-[#dce5dd] bg-[#eef3ed] p-5 text-sm leading-6 text-[#52685c]; }
</style>
