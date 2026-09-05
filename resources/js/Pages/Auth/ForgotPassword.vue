<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const form = useForm({
    email: '',
});

const page = usePage();
const status = page.props.flash.status;

const submit = (): void => {
    form.post('/forgot-password');
};
</script>

<template>
    <AuthLayout title="Recuperar contraseña">
        <div v-if="status" class="app-alert app-alert--success">
            {{ status }}
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <p class="text-sm leading-6 text-[#60766a]">
                Introduce tu email y te enviaremos un enlace para restablecer tu contraseña.
            </p>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-semibold text-[#33483e]">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus
                    class="app-field"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="app-button app-button--primary w-full"
            >
                Enviar enlace
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-600">
            <a href="/login" class="font-medium text-emerald-700 hover:underline">Volver al inicio de sesión</a>
        </p>
    </AuthLayout>
</template>
