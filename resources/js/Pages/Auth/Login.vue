<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = (): void => {
    form.post('/login');
};
</script>

<template>
    <AuthLayout title="Iniciar sesión">
        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="form.errors.email" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ form.errors.email }}
            </div>

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
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-zinc-700">Contraseña</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-zinc-600">
                    <input v-model="form.remember" type="checkbox" class="rounded border-zinc-300" />
                    Recordarme
                </label>
                <a href="/forgot-password" class="text-sm text-emerald-700 hover:underline">
                    ¿Olvidaste tu contraseña?
                </a>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
            >
                Iniciar sesión
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-600">
            ¿No tienes cuenta?
            <a href="/register" class="font-medium text-emerald-700 hover:underline">Regístrate</a>
        </p>
    </AuthLayout>
</template>
