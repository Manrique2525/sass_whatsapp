<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const user = page.props.auth.user;
</script>

<template>
    <AppLayout :user="user">
        <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
            <h2 class="text-xl font-semibold text-zinc-900">Hola, {{ user?.name }}</h2>
            <p class="mt-2 text-sm text-zinc-600">
                Autenticación operativa. Aquí aparecerán los módulos del producto en las siguientes fases.
            </p>
        </div>
    </AppLayout>
</template>
