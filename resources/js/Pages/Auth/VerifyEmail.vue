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
        <div v-if="status" class="app-alert app-alert--success mb-4">
            {{ status }}
        </div>

        <div class="space-y-4">
            <p class="text-sm leading-6 text-[#60766a]">
                Te hemos enviado un enlace de verificación. Revisa tu bandeja de entrada.
            </p>

            <button
                type="button"
                class="app-button app-button--primary w-full"
                @click="resend"
            >
                Reenviar enlace de verificación
            </button>

            <form method="post" action="/logout" class="text-center">
                <button type="submit" class="text-sm font-medium text-[#71877b] hover:text-[#0b8f5a] hover:underline">Cerrar sesión</button>
            </form>
        </div>
    </AuthLayout>
</template>
