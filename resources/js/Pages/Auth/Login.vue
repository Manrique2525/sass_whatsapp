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
            <div v-if="form.errors.email" class="app-alert app-alert--error">
                {{ form.errors.email }}
            </div>

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
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-semibold text-[#33483e]">Contraseña</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="app-field"
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
                class="app-button app-button--primary w-full"
            >
                Iniciar sesión
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-600">
            <span class="text-[#71877b]">¿No tienes cuenta?</span>
            <a href="/register" class="font-semibold text-[#0b8f5a] hover:underline">Regístrate</a>
        </p>
    </AuthLayout>
</template>
