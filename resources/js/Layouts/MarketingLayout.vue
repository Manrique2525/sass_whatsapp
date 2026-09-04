<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const menuOpen = ref(false);
const appName = 'WhatsApp SaaS';

const closeMenu = (): void => {
    menuOpen.value = false;
};
</script>

<template>
    <Head>
        <title>Plataforma de automatización para WhatsApp | {{ appName }}</title>
        <meta
            name="description"
            content="Automatiza conversaciones, organiza a tu equipo y convierte más clientes con un inbox compartido, flujos, IA y analytics para WhatsApp Business."
        />
        <meta property="og:title" content="Automatiza WhatsApp. Atiende mejor." />
        <meta
            property="og:description"
            content="Un inbox compartido para tu equipo, automatizaciones visuales e IA con el conocimiento de tu negocio."
        />
        <meta property="og:type" content="website" />
        <meta property="og:url" content="/" />
        <link rel="canonical" href="/" />
    </Head>

    <div class="marketing-shell min-h-screen overflow-hidden bg-[#f7f8f3] text-[#10261f]">
        <header class="fixed inset-x-0 top-0 z-50 border-b border-[#dce5dd]/70 bg-[#f7f8f3]/90 backdrop-blur-xl">
            <nav class="mx-auto flex h-20 max-w-7xl items-center justify-between px-5 sm:px-8 lg:px-10" aria-label="Navegación principal">
                <a href="#inicio" class="flex items-center gap-3" @click="closeMenu">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#b7f36b] text-sm font-black text-[#10261f]">w.</span>
                    <span class="text-sm font-bold tracking-[-0.02em] text-[#10261f] sm:text-base">{{ appName }}</span>
                </a>

                <button
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#cbd8cf] text-[#10261f] transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-[#10261f] lg:hidden"
                    :aria-expanded="menuOpen"
                    aria-controls="marketing-menu"
                    :aria-label="menuOpen ? 'Cerrar menú' : 'Abrir menú'"
                    @click="menuOpen = !menuOpen"
                >
                    <span class="text-lg" aria-hidden="true">{{ menuOpen ? '×' : '☰' }}</span>
                </button>

                <div id="marketing-menu" class="hidden items-center gap-8 lg:flex">
                    <a href="#funciones" class="marketing-nav-link">Funciones</a>
                    <a href="#como-funciona" class="marketing-nav-link">Cómo funciona</a>
                    <a href="#seguridad" class="marketing-nav-link">Seguridad</a>
                    <a href="#faq" class="marketing-nav-link">FAQ</a>
                </div>

                <div class="hidden items-center gap-5 lg:flex">
                    <Link v-if="page.props.auth.user" href="/dashboard" class="marketing-login">Ir al panel</Link>
                    <Link v-else href="/login" class="marketing-login">Iniciar sesión</Link>
                    <Link href="/register" class="marketing-button marketing-button--small">Empezar gratis <span aria-hidden="true">↗</span></Link>
                </div>
            </nav>

            <div v-if="menuOpen" data-testid="marketing-mobile-menu" class="border-t border-[#dce5dd] bg-[#f7f8f3] px-5 py-5 lg:hidden">
                <div class="mx-auto flex max-w-7xl flex-col gap-4">
                    <a href="#funciones" class="marketing-mobile-link" @click="closeMenu">Funciones</a>
                    <a href="#como-funciona" class="marketing-mobile-link" @click="closeMenu">Cómo funciona</a>
                    <a href="#seguridad" class="marketing-mobile-link" @click="closeMenu">Seguridad</a>
                    <a href="#faq" class="marketing-mobile-link" @click="closeMenu">FAQ</a>
                    <div class="mt-2 flex items-center gap-4 border-t border-[#dce5dd] pt-4">
                        <Link v-if="page.props.auth.user" href="/dashboard" class="marketing-login" @click="closeMenu">Ir al panel</Link>
                        <Link v-else href="/login" class="marketing-login" @click="closeMenu">Iniciar sesión</Link>
                        <Link href="/register" class="marketing-button marketing-button--small" @click="closeMenu">Empezar gratis <span aria-hidden="true">↗</span></Link>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <slot />
        </main>

        <footer class="border-t border-[#dce5dd] bg-[#f0f3ec]">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-5 py-10 sm:px-8 md:flex-row md:items-center md:justify-between lg:px-10">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-[#10261f] text-xs font-black text-[#b7f36b]">w.</span>
                        <span class="font-bold">{{ appName }}</span>
                    </div>
                    <p class="mt-3 max-w-sm text-sm leading-6 text-[#64756d]">Conversaciones más claras. Equipos más rápidos. Negocios que no dejan oportunidades en visto.</p>
                </div>
                <p class="text-sm text-[#64756d]">© {{ new Date().getFullYear() }} {{ appName }}. Hecho para equipos que atienden por WhatsApp.</p>
            </div>
        </footer>
    </div>
</template>
