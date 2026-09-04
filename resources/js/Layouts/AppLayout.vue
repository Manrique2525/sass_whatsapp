<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { AuthUser } from '@/types/inertia';
import NotificationBell from '@/Components/Notifications/NotificationBell.vue';

const props = defineProps<{
    user: AuthUser | null;
    fullWidth?: boolean;
}>();

const page = usePage();

const tenants = computed(() => page.props.auth.tenants);
const currentTenantId = computed(() => page.props.auth.current_tenant_id);
const switching = ref(false);
const error = ref<string | null>(null);
const mobileNavOpen = ref(false);
const permissions = computed(() => page.props.auth.permissions ?? []);

const can = (permission: string): boolean => permissions.value.includes(permission);

const navigation = computed(() => [
    { label: 'Inicio', href: '/dashboard', permission: null },
    { label: 'Conversaciones', href: '/settings/conversations', permission: 'conversations.view' },
    { label: 'Flujos', href: '/settings/flows', permission: 'flows.view' },
    { label: 'FAQs', href: '/settings/faq', permission: 'faqs.view' },
    { label: 'Leads', href: '/settings/leads', permission: 'leads.view' },
    { label: 'Knowledge', href: '/settings/knowledge', permission: 'knowledge.view' },
    { label: 'Contactos', href: '/settings/contacts', permission: 'contacts.view' },
    { label: 'Analytics', href: '/settings/analytics', permission: 'analytics.view' },
    { label: 'Usuarios', href: '/settings/users', permission: 'users.view' },
    { label: 'Perfil de negocio', href: '/settings/business-profile', permission: 'business_profile.view' },
    { label: 'WhatsApp', href: '/settings/whatsapp', permission: 'whatsapp.view' },
    { label: 'Billing', href: '/settings/billing', permission: 'billing.view' },
].filter((item) => item.permission === null || can(item.permission)));

const isActive = (href: string): boolean => page.url === href || page.url.startsWith(`${href}/`);

const switchTenant = async (tenantId: string): Promise<void> => {
    if (tenantId === currentTenantId.value || switching.value) {
        return;
    }

    switching.value = true;
    error.value = null;

    try {
        await window.axios.post(`/api/v1/tenants/${tenantId}/switch`);
        router.reload({ only: ['auth'] });
    } catch {
        error.value = 'No se pudo cambiar de tenant.';
    } finally {
        switching.value = false;
    }
};

const logout = (): void => {
    router.post('/logout');
};

const closeMobileNav = (): void => {
    mobileNavOpen.value = false;
};

const closeOnEscape = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') closeMobileNav();
};

onMounted(() => window.addEventListener('keydown', closeOnEscape));
onBeforeUnmount(() => window.removeEventListener('keydown', closeOnEscape));
</script>

<template>
    <div class="min-h-screen bg-zinc-100">
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
                <div class="flex items-center gap-4">
                    <Link href="/dashboard" class="font-semibold text-zinc-900">WhatsApp SaaS</Link>
                    <select
                        v-if="tenants.length > 0"
                        class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-700"
                        :value="currentTenantId ?? undefined"
                        :disabled="switching"
                        @change="switchTenant(($event.target as HTMLSelectElement).value)"
                    >
                        <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
                            {{ tenant.name }}
                        </option>
                    </select>
                    <span
                        v-else-if="currentTenantId === null"
                        class="text-xs text-zinc-400"
                    >
                        Sin tenant asignado
                    </span>
                </div>
                <div class="flex items-center gap-4">
                    <NotificationBell v-if="currentTenantId !== null" />
                    <div class="hidden text-right sm:block">
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
                    <button
                        v-if="currentTenantId !== null"
                        type="button"
                        class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm text-zinc-700 sm:hidden"
                        :aria-expanded="mobileNavOpen"
                        aria-controls="mobile-navigation"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        Menú
                    </button>
                </div>
            </div>
            <p v-if="error" class="border-t border-zinc-200 bg-red-50 px-4 py-2 text-sm text-red-600">
                {{ error }}
            </p>
            <nav
                v-if="currentTenantId !== null"
                data-testid="authenticated-navigation"
                class="hidden border-t border-zinc-200 bg-white sm:block"
            >
                <div class="mx-auto flex max-w-6xl gap-5 overflow-x-auto px-4 py-2 text-sm">
                    <Link v-for="item in navigation" :key="item.href" :href="item.href" class="whitespace-nowrap rounded px-1 py-1 text-zinc-600 hover:text-zinc-900" :class="isActive(item.href) ? 'font-semibold text-zinc-900' : ''" :aria-current="isActive(item.href) ? 'page' : undefined">
                        {{ item.label }}
                    </Link>
                </div>
            </nav>
            <nav
                v-if="currentTenantId !== null && mobileNavOpen"
                id="mobile-navigation"
                data-testid="mobile-navigation"
                class="border-t border-zinc-200 bg-white p-3 sm:hidden"
            >
                <div class="grid gap-1">
                    <Link v-for="item in navigation" :key="item.href" :href="item.href" class="rounded-md px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-100" :class="isActive(item.href) ? 'bg-zinc-100 font-semibold text-zinc-900' : ''" :aria-current="isActive(item.href) ? 'page' : undefined" @click="closeMobileNav">
                        {{ item.label }}
                    </Link>
                </div>
            </nav>
        </header>

        <main class="mx-auto px-4 py-8" :class="props.fullWidth ? 'max-w-[1400px]' : 'max-w-6xl'">
            <slot />
        </main>
    </div>
</template>
