<script setup lang="ts">
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    user: {
        name: string;
        email: string;
    } | null;
}>();

const logout = (): void => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-zinc-100">
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <span class="font-semibold text-zinc-900">WhatsApp SaaS</span>
                <div class="flex items-center gap-4">
                    <div class="text-right">
                        <p class="text-sm font-medium text-zinc-800">{{ props.user?.name }}</p>
                        <p class="text-xs text-zinc-500">{{ props.user?.email }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50"
                        @click="logout"
                    >
                        Cerrar sesión
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8">
            <slot />
        </main>
    </div>
</template>
