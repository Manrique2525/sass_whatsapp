<script setup lang="ts">
defineProps<{
    title?: string;
}>();
</script>

<template>
    <div class="min-h-screen bg-[#f7f8f3] text-[#10261f] lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(420px,0.8fr)]">
        <div class="relative hidden overflow-hidden bg-[#10261f] px-12 py-10 text-white lg:flex lg:flex-col lg:justify-between xl:px-20">
            <div class="absolute -right-28 top-20 h-72 w-72 rounded-full bg-[#b7f36b]/15 blur-3xl" />
            <div class="absolute -bottom-24 left-8 h-64 w-64 rounded-full bg-[#0b8f5a]/35 blur-3xl" />
            <a href="/" class="relative flex items-center gap-3 text-sm font-semibold tracking-tight">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#b7f36b] text-[#10261f]">W</span>
                WhatsApp SaaS
            </a>
            <div class="relative max-w-lg">
                <p class="mb-5 text-xs font-semibold uppercase tracking-[0.22em] text-[#b7f36b]">Atención que escala</p>
                <h2 class="text-4xl font-semibold leading-[1.05] tracking-[-0.04em] xl:text-6xl">Convierte cada conversación en una relación.</h2>
                <p class="mt-6 max-w-md text-base leading-7 text-[#d4e4d9]">Automatiza WhatsApp, entiende a tus clientes y deja que tu equipo se concentre en lo que importa.</p>
            </div>
            <p class="relative text-xs text-[#9bb5a6]">Simple para empezar. Potente para crecer.</p>
        </div>

        <div class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-8">
            <div class="w-full max-w-md">
                <div class="mb-8 flex items-center justify-center gap-3 lg:justify-start">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#10261f] text-sm font-bold text-[#b7f36b] lg:hidden">W</span>
                    <div>
                        <p class="app-eyebrow">WhatsApp SaaS</p>
                        <h1 class="mt-1 text-2xl font-semibold tracking-[-0.03em] text-[#10261f]">{{ title ?? 'WhatsApp SaaS' }}</h1>
                    </div>
                </div>

                <div class="app-card p-6 sm:p-8">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
