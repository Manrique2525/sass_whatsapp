<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { AuthUser } from '@/types/inertia';

const props = defineProps<{
    user: AuthUser | null;
}>();

const page = usePage();
const mobileNavOpen = ref(false);

const navigation = [
    { label: 'Overview', href: '/platform' },
    { label: 'Customers', href: '/platform/customers' },
];

const isActive = (href: string): boolean => page.url === href || page.url.startsWith(`${href}/`);

const logout = (): void => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-[#f5f8f4] text-[#10261f]">
        <header class="border-b border-[#dce8df] bg-white/95 backdrop-blur-xl lg:sticky lg:top-0 lg:z-30">
            <div class="mx-auto flex max-w-[1400px] items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <Link href="/platform" class="flex shrink-0 items-center gap-2.5 text-sm font-bold tracking-tight">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#10261f] text-[#b7f36b]">W</span>
                        <span class="hidden sm:inline">WhatsApp SaaS</span>
                    </Link>
                    <span class="rounded-full border border-[#b7f36b] bg-[#eff9e8] px-2.5 py-1 text-[10px] font-bold tracking-[0.14em] text-[#176b42]">
                        PLATFORM ADMIN
                    </span>
                </div>

                <div class="flex items-center gap-3">
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-semibold">{{ props.user?.name }}</p>
                        <p class="text-xs text-[#71877b]">{{ props.user?.email }}</p>
                    </div>
                    <button type="button" class="app-button app-button--secondary hidden sm:inline-flex" @click="logout">
                        Cerrar sesión
                    </button>
                    <button
                        type="button"
                        class="app-button app-button--secondary sm:hidden"
                        :aria-expanded="mobileNavOpen"
                        aria-controls="platform-navigation"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        Menú
                    </button>
                </div>
            </div>

            <nav class="hidden border-t border-[#edf2ec] bg-white sm:block" data-testid="platform-navigation">
                <div class="mx-auto flex max-w-[1400px] gap-1 overflow-x-auto px-4 py-2 sm:px-6">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        class="whitespace-nowrap rounded-xl px-3 py-2 text-sm font-medium text-[#71877b] transition hover:bg-[#f0f5ef] hover:text-[#10261f]"
                        :class="isActive(item.href) ? 'bg-[#eef8ed] font-semibold text-[#10261f]' : ''"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </Link>
                </div>
            </nav>

            <nav v-if="mobileNavOpen" id="platform-navigation" class="border-t border-[#edf2ec] bg-white p-3 sm:hidden">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    class="block rounded-xl px-3 py-2.5 text-sm font-medium text-[#33483e] hover:bg-[#f0f5ef]"
                    :class="isActive(item.href) ? 'bg-[#eef8ed] font-semibold text-[#10261f]' : ''"
                    @click="mobileNavOpen = false"
                >
                    {{ item.label }}
                </Link>
                <button type="button" class="mt-2 w-full rounded-xl px-3 py-2.5 text-left text-sm font-medium text-[#33483e] hover:bg-[#f0f5ef]" @click="logout">
                    Cerrar sesión
                </button>
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:py-10">
            <slot />
        </main>
    </div>
</template>
