<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { AuthUser } from '@/types/inertia';

const props = defineProps<{
    user: AuthUser | null;
    fullWidth?: boolean;
}>();

const page = usePage();

const tenants = computed(() => page.props.auth.tenants);
const currentTenantId = computed(() => page.props.auth.current_tenant_id);
const switching = ref(false);
const error = ref<string | null>(null);

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
</script>

<template>
    <div class="min-h-screen bg-zinc-100">
        <header class="border-b border-zinc-200 bg-white">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <div class="flex items-center gap-4">
                    <span class="font-semibold text-zinc-900">WhatsApp SaaS</span>
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
            <p v-if="error" class="border-t border-zinc-200 bg-red-50 px-4 py-2 text-sm text-red-600">
                {{ error }}
            </p>
            <nav
                v-if="currentTenantId !== null"
                class="border-t border-zinc-200 bg-white"
            >
                <div class="mx-auto flex max-w-4xl gap-6 px-4 py-2 text-sm">
                    <Link href="/settings/users" class="text-zinc-600 hover:text-zinc-900">Usuarios</Link>
                    <Link href="/settings/business-profile" class="text-zinc-600 hover:text-zinc-900">Perfil de negocio</Link>
                    <Link href="/settings/whatsapp" class="text-zinc-600 hover:text-zinc-900">WhatsApp</Link>
                    <Link href="/settings/contacts" class="text-zinc-600 hover:text-zinc-900">Contactos</Link>
                    <Link href="/settings/conversations" class="text-zinc-600 hover:text-zinc-900">Conversaciones</Link>
                    <Link href="/settings/flows" class="text-zinc-600 hover:text-zinc-900">Flujos</Link>
                    <Link href="/settings/faq" class="text-zinc-600 hover:text-zinc-900">FAQs</Link>
                </div>
            </nav>
        </header>

        <main class="mx-auto px-4 py-8" :class="props.fullWidth ? 'max-w-[1400px]' : 'max-w-4xl'">
            <slot />
        </main>
    </div>
</template>
