<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';

const user = usePage().props.auth.user;
</script>

<template>
    <PlatformLayout :user="user">
        <section class="app-card relative overflow-hidden p-6 sm:p-8">
            <div class="absolute -right-20 -top-24 h-64 w-64 rounded-full bg-[#b7f36b]/25 blur-3xl" />
            <p class="app-eyebrow relative">Global workspace</p>
            <h1 class="relative mt-2 text-3xl font-semibold tracking-[-0.04em]">Platform Admin</h1>
            <p class="relative mt-3 max-w-2xl text-sm leading-6 text-[#60766a]">
                Esta es la frontera administrativa global de la plataforma. Los módulos de clientes, planes,
                suscripciones, uso y auditoría se habilitarán en unidades posteriores.
            </p>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-2">
            <div class="app-card p-6">
                <p class="app-eyebrow">Scope</p>
                <h2 class="mt-2 text-lg font-semibold">Platform-level access</h2>
                <p class="mt-2 text-sm leading-6 text-[#60766a]">No tenant is selected or required for this area.</p>
            </div>
            <div class="app-card p-6">
                <p class="app-eyebrow">Status</p>
                <h2 class="mt-2 text-lg font-semibold">Foundation active</h2>
                <p class="mt-2 text-sm leading-6 text-[#60766a]">Administrative capabilities are being introduced incrementally.</p>
            </div>
        </section>

        <section class="app-card mt-6 flex flex-wrap items-center justify-between gap-4 p-6">
            <div>
                <p class="app-eyebrow">Administration</p>
                <h2 class="mt-2 text-lg font-semibold">Manage customers</h2>
                <p class="mt-2 text-sm text-[#60766a]">Review tenant health and account context without entering a tenant.</p>
            </div>
            <Link href="/platform/customers" class="app-button app-button--primary">Open Customers</Link>
        </section>
    </PlatformLayout>
</template>
