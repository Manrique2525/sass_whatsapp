<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { AuthUser } from '@/types/inertia';
import NotificationBell from '@/Components/Notifications/NotificationBell.vue';
import AppSelect from '@/Components/AppSelect.vue';

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
    <div class="app-shell">
        <header class="border-b border-[#dce8df] bg-white/90 backdrop-blur-xl lg:sticky lg:top-0 lg:z-30">
            <div class="mx-auto flex max-w-[1400px] items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <div class="flex min-w-0 items-center gap-3">
                    <Link href="/dashboard" class="flex shrink-0 items-center gap-2.5 text-sm font-bold tracking-tight text-[#10261f]">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#10261f] text-[#b7f36b]">W</span>
                        <span class="hidden sm:inline">WhatsApp SaaS</span>
                    </Link>
                    <AppSelect
                        v-if="tenants.length > 0"
                        class="w-[150px] shrink-0 sm:w-auto sm:min-w-[180px]"
                        :model-value="currentTenantId"
                        :options="tenants.map((tenant) => ({ value: tenant.id, label: tenant.name }))"
                        :disabled="switching"
                        searchable
                        aria-label="Tenant activo"
                        @update:model-value="switchTenant($event as string)"
                    />
                    <span
                        v-else-if="currentTenantId === null"
                        class="text-xs text-[#71877b]"
                    >
                        Sin tenant asignado
                    </span>
                </div>
                <div class="flex items-center gap-2 sm:gap-4">
                    <NotificationBell v-if="currentTenantId !== null" />
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-semibold text-[#10261f]">{{ props.user?.name }}</p>
                        <p class="text-xs text-[#71877b]">{{ props.user?.email }}</p>
                    </div>
                    <button
                        type="button"
                        class="app-button app-button--secondary hidden sm:inline-flex"
                        @click="logout"
                    >
                        Cerrar sesión
                    </button>
                    <button
                        v-if="currentTenantId !== null"
                        type="button"
                        class="app-button app-button--secondary sm:hidden"
                        :aria-expanded="mobileNavOpen"
                        aria-controls="mobile-navigation"
                        @click="mobileNavOpen = !mobileNavOpen"
                    >
                        Menú
                    </button>
                </div>
            </div>
            <p v-if="error" class="border-t border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700">
                {{ error }}
            </p>
            <nav
                v-if="currentTenantId !== null"
                data-testid="authenticated-navigation"
                class="hidden border-t border-[#edf2ec] bg-white sm:block"
            >
                <div class="mx-auto flex max-w-[1400px] gap-1 overflow-x-auto px-4 py-2 sm:px-6">
                    <Link v-for="item in navigation" :key="item.href" :href="item.href" class="whitespace-nowrap rounded-xl px-3 py-2 text-sm font-medium text-[#71877b] transition hover:bg-[#f0f5ef] hover:text-[#10261f]" :class="isActive(item.href) ? 'bg-[#eef8ed] font-semibold text-[#10261f]' : ''" :aria-current="isActive(item.href) ? 'page' : undefined">
                        {{ item.label }}
                    </Link>
                </div>
            </nav>
            <nav
                v-if="currentTenantId !== null && mobileNavOpen"
                id="mobile-navigation"
                data-testid="mobile-navigation"
                class="border-t border-[#edf2ec] bg-white p-3 sm:hidden"
            >
                <div class="grid gap-1">
                        <Link v-for="item in navigation" :key="item.href" :href="item.href" class="rounded-xl px-3 py-2.5 text-sm font-medium text-[#33483e] hover:bg-[#f0f5ef]" :class="isActive(item.href) ? 'bg-[#eef8ed] font-semibold text-[#10261f]' : ''" :aria-current="isActive(item.href) ? 'page' : undefined" @click="closeMobileNav">
                        {{ item.label }}
                    </Link>
                </div>
            </nav>
        </header>

        <main class="mx-auto px-4 py-8 sm:px-6 lg:py-10" :class="props.fullWidth ? 'max-w-[1400px]' : 'max-w-6xl'">
            <slot />
        </main>
    </div>
</template>
