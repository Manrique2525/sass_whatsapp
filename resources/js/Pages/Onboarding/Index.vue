<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { fetchCurrentSubscription } from '@/features/billing/billingApi';
import type { Subscription } from '@/features/billing/billingTypes';
import { trackMarketingEvent } from '@/features/marketing/marketingAnalytics';

const page = usePage();
const user = page.props.auth.user;
const tenants = computed(() => page.props.auth.tenants);
const currentTenantId = computed(() => page.props.auth.current_tenant_id as string | null);
const permissions = computed(() => page.props.auth.permissions as string[]);

const can = (permission: string): boolean => permissions.value.includes(permission);
const canManageWhatsApp = computed(() => can('whatsapp.manage'));

const loading = ref(true);
const error = ref<string | null>(null);
const subscription = ref<Subscription | null>(null);

const currentTenant = computed(() =>
  tenants.value.find((t) => t.id === currentTenantId.value),
);

const load = async (): Promise<void> => {
  if (!currentTenantId.value) {
    loading.value = false;
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    subscription.value = await fetchCurrentSubscription(currentTenantId.value);
  } catch {
    error.value = 'No se pudo cargar el estado del plan.';
  } finally {
    loading.value = false;
  }
};

const workspaceReady = computed(() => currentTenant.value !== undefined);

onMounted(() => {
  trackMarketingEvent('onboarding_viewed', {}, { once: true });
  load();
});
</script>

<template>
    <AppLayout :user="user">
        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h2 class="text-2xl font-semibold text-zinc-900">¡Bienvenido!</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    Tu workspace está listo. Completa los siguientes pasos para empezar a
                    atender a tus clientes por WhatsApp.
                </p>
            </div>

            <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ error }}
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-medium text-zinc-500">Workspace</p>
                    <p class="mt-2 text-lg font-semibold text-zinc-900">
                        {{ currentTenant?.name ?? '—' }}
                    </p>
                    <p v-if="workspaceReady" class="mt-2 text-xs text-emerald-700">● Creado</p>
                    <p v-else class="mt-2 text-xs text-zinc-400">Pendiente</p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-medium text-zinc-500">Plan</p>
                    <template v-if="loading">
                        <p class="mt-2 text-sm text-zinc-400">Cargando...</p>
                    </template>
                    <template v-else-if="subscription && subscription.status === 'active'">
                        <p class="mt-2 text-lg font-semibold text-zinc-900">{{ subscription.plan.name }}</p>
                        <p class="mt-2 text-xs text-emerald-700">● Activo</p>
                    </template>
                    <template v-else>
                        <p class="mt-2 text-sm text-zinc-400">Sin suscripción activa</p>
                    </template>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-medium text-zinc-500">Configuración</p>
                    <p v-if="canManageWhatsApp" class="mt-2 text-xs text-amber-600">● Falta conectar WhatsApp</p>
                    <p v-else class="mt-2 text-xs text-zinc-400">—</p>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900">Siguientes pasos</h3>
                <ol class="mt-4 list-decimal space-y-2 text-sm text-zinc-700">
                    <li>Conecta tu cuenta de WhatsApp Business para recibir mensajes.</li>
                    <li>Configura tu perfil de negocio y tus flujos de atención.</li>
                    <li>Empieza a gestionar tus conversaciones desde el inbox.</li>
                </ol>

                <div class="mt-6 flex flex-wrap gap-3">
                    <Link
                        v-if="canManageWhatsApp"
                        href="/settings/whatsapp"
                        class="rounded-md bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                        @click="trackMarketingEvent('whatsapp_connect_clicked')"
                    >
                        Conectar WhatsApp
                    </Link>
                    <Link
                        href="/dashboard"
                        class="rounded-md border border-zinc-300 px-5 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
                    >
                        Explorar la plataforma
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
