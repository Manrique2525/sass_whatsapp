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
        <div v-if="status" class="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            {{ status }}
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-zinc-600">
                Introduce tu email y te enviaremos un enlace para restablecer tu contraseña.
            </p>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-zinc-700">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
            >
                Enviar enlace
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-600">
            <a href="/login" class="font-medium text-emerald-700 hover:underline">Volver al inicio de sesión</a>
        </p>
    </AuthLayout>
</template>
