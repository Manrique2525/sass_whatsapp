<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const page = usePage();
const status = page.props.flash.status;

const resend = (): void => {
    router.post('/email/resend');
};
</script>

<template>
    <AuthLayout title="Verifica tu email">
        <div v-if="status" class="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ status }}
        </div>

        <div class="space-y-4">
            <p class="text-sm text-zinc-600">
                Te hemos enviado un enlace de verificación. Revisa tu bandeja de entrada.
            </p>

            <button
                type="button"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                @click="resend"
            >
                Reenviar enlace de verificación
            </button>

            <form method="post" action="/logout" class="text-center">
                <button type="submit" class="text-sm text-zinc-500 hover:underline">Cerrar sesión</button>
            </form>
        </div>
    </AuthLayout>
</template>
