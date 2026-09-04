<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { onMounted, ref } from 'vue';
import { trackMarketingEvent } from '@/features/marketing/marketingAnalytics';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const registrationStarted = ref(false);

onMounted(() => trackMarketingEvent('register_viewed', {}, { once: true }));

const submit = (): void => {
    if (!registrationStarted.value) {
        registrationStarted.value = true;
        trackMarketingEvent('registration_started', {}, { once: true });
    }
    form.post('/register', {
        onSuccess: () => trackMarketingEvent('registration_completed', {}, { once: true }),
    });
};
</script>

<template>
    <AuthLayout title="Crea tu espacio de trabajo">
        <p class="mb-6 text-center text-sm leading-6 text-zinc-600">
            Empieza gratis y configura tu espacio de trabajo.
        </p>
        <div class="mb-6 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2.5 text-center text-xs leading-5 text-emerald-900">
            Tu cuenta incluye un workspace y el plan Free. Después podrás conectar tu WhatsApp Business.
        </div>
        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <label for="name" class="mb-1 block text-sm font-medium text-zinc-700">Nombre</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    required
                    autofocus
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-zinc-700">Email</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-zinc-700">Contraseña</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-zinc-700">
                    Confirmar contraseña
                </label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
            >
                Crear mi espacio
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-zinc-600">
            ¿Ya tienes cuenta?
            <a href="/login" class="font-medium text-emerald-700 hover:underline">Inicia sesión</a>
        </p>
        <p class="mt-3 text-center text-sm">
            <Link href="/" class="font-medium text-zinc-600 hover:text-emerald-700 hover:underline">Volver a la página principal</Link>
        </p>
    </AuthLayout>
</template>
