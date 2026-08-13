<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const user = page.props.auth.user;
const currentTenant = page.props.auth.tenants.find(
    (tenant) => tenant.id === page.props.auth.current_tenant_id,
);
</script>

<template>
    <AppLayout :user="user">
        <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
            <h2 class="text-xl font-semibold text-zinc-900">Hola, {{ user?.name }}</h2>
            <p class="mt-2 text-sm text-zinc-600">
                Autenticación operativa. Aquí aparecerán los módulos del producto en las siguientes fases.
            </p>
        </div>

        <div class="mt-6 rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
            <h3 class="text-lg font-semibold text-zinc-900">Tenant activo</h3>
            <p class="mt-2 text-sm text-zinc-600">
                <template v-if="currentTenant">
                    {{ currentTenant.name }}
                    <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700">
                        {{ currentTenant.status }}
                    </span>
                </template>
                <template v-else>
                    Sin tenant activo. Selecciona uno con el selector superior.
                </template>
            </p>
        </div>
    </AppLayout>
</template>
