<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const menuOpen = ref(false);
const menuTrigger = ref<HTMLButtonElement | null>(null);
const appName = import.meta.env.VITE_APP_NAME ?? 'WhatsApp SaaS';

const props = withDefaults(defineProps<{ pageTitle?: string; pageDescription?: string }>(), {
    pageTitle: 'Plataforma de automatización para WhatsApp',
    pageDescription: 'Automatiza conversaciones, organiza a tu equipo y convierte más clientes con un inbox compartido, flujos, IA y analytics para WhatsApp Business.',
});

const documentTitle = computed(() => props.pageTitle.endsWith(` | ${appName}`) ? props.pageTitle : `${props.pageTitle} | ${appName}`);
const canonicalUrl = computed(() => {
    if (typeof window === 'undefined') return page.url;

    const url = new URL(page.url, window.location.origin);
    url.search = '';
    url.hash = '';

    return url.toString();
});

const closeMenu = (restoreFocus = false): void => {
    menuOpen.value = false;

    if (restoreFocus) menuTrigger.value?.focus();
};

const handleMenuKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape' && menuOpen.value) closeMenu(true);
};

onMounted(() => window.addEventListener('keydown', handleMenuKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleMenuKeydown));

const toggleMenu = (): void => {
    menuOpen.value = !menuOpen.value;
};
</script>

<template>
    <Head>
        <title>{{ documentTitle }}</title>
        <meta name="description" :content="pageDescription" />
        <meta property="og:title" :content="documentTitle" />
        <meta property="og:description" :content="pageDescription" />
        <meta property="og:type" content="website" />
        <meta property="og:url" :content="canonicalUrl" />
        <meta name="twitter:card" content="summary" />
        <meta name="twitter:title" :content="documentTitle" />
        <meta name="twitter:description" :content="pageDescription" />
        <link rel="canonical" :href="canonicalUrl" />
    </Head>

    <div class="marketing-shell min-h-screen overflow-hidden bg-[#f7f8f3] text-[#10261f]">
        <header class="fixed inset-x-0 top-0 z-50 border-b border-[#dce5dd]/70 bg-[#f7f8f3]/90 backdrop-blur-xl">
            <nav class="mx-auto flex h-20 max-w-7xl items-center justify-between px-5 sm:px-8 lg:px-10" aria-label="Navegación principal">
                <a href="#inicio" class="flex items-center gap-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#10261f]" @click="closeMenu(false)">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#b7f36b] text-sm font-black text-[#10261f]">w.</span>
                    <span class="text-sm font-bold tracking-[-0.02em] text-[#10261f] sm:text-base">{{ appName }}</span>
                </a>

                <button
                    ref="menuTrigger"
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#cbd8cf] text-[#10261f] transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#10261f] lg:hidden"
                    :aria-expanded="menuOpen"
                    aria-controls="marketing-menu"
                    :aria-label="menuOpen ? 'Cerrar menú' : 'Abrir menú'"
                    @click="toggleMenu"
                >
                    <span class="text-lg" aria-hidden="true">{{ menuOpen ? '×' : '☰' }}</span>
                </button>

                <div id="marketing-menu" class="hidden items-center gap-8 lg:flex">
                    <a href="#funciones" class="marketing-nav-link">Funciones</a>
                    <a href="#plan" class="marketing-nav-link">Plan</a>
                    <a href="#como-funciona" class="marketing-nav-link">Cómo funciona</a>
                    <a href="#seguridad" class="marketing-nav-link">Seguridad</a>
                    <a href="#faq" class="marketing-nav-link">FAQ</a>
                </div>

                <div class="hidden items-center gap-5 lg:flex">
                    <Link v-if="page.props.auth.user" href="/dashboard" class="marketing-button marketing-button--small">Ir al panel <span aria-hidden="true">↗</span></Link>
                    <template v-else>
                        <Link href="/login" class="marketing-login">Iniciar sesión</Link>
                        <Link href="/register" class="marketing-button marketing-button--small">Empezar gratis <span aria-hidden="true">↗</span></Link>
                    </template>
                </div>
            </nav>

            <div v-if="menuOpen" data-testid="marketing-mobile-menu" class="border-t border-[#dce5dd] bg-[#f7f8f3] px-5 py-5 lg:hidden">
                <div class="mx-auto flex max-w-7xl flex-col gap-4">
                    <a href="#funciones" class="marketing-mobile-link" @click="closeMenu(false)">Funciones</a>
                    <a href="#plan" class="marketing-mobile-link" @click="closeMenu(false)">Plan</a>
                    <a href="#como-funciona" class="marketing-mobile-link" @click="closeMenu(false)">Cómo funciona</a>
                    <a href="#seguridad" class="marketing-mobile-link" @click="closeMenu(false)">Seguridad</a>
                    <a href="#faq" class="marketing-mobile-link" @click="closeMenu(false)">FAQ</a>
                    <div class="mt-2 flex items-center gap-4 border-t border-[#dce5dd] pt-4">
                        <Link v-if="page.props.auth.user" href="/dashboard" class="marketing-button marketing-button--small" @click="closeMenu(false)">Ir al panel <span aria-hidden="true">↗</span></Link>
                        <template v-else>
                            <Link href="/login" class="marketing-login" @click="closeMenu(false)">Iniciar sesión</Link>
                            <Link href="/register" class="marketing-button marketing-button--small" @click="closeMenu(false)">Empezar gratis <span aria-hidden="true">↗</span></Link>
                        </template>
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
                <div class="grid gap-6 text-sm sm:grid-cols-3 sm:gap-10">
                    <div><p class="font-semibold text-[#10261f]">Producto</p><div class="mt-3 grid gap-2 text-[#64756d]"><a href="#funciones" class="hover:text-[#0b8f5a]">Funciones</a><a href="#seguridad" class="hover:text-[#0b8f5a]">Seguridad</a><a href="#faq" class="hover:text-[#0b8f5a]">FAQ</a></div></div>
                    <div><p class="font-semibold text-[#10261f]">Cuenta</p><div class="mt-3 grid gap-2 text-[#64756d]"><Link v-if="page.props.auth.user" href="/dashboard" class="hover:text-[#0b8f5a]">Ir al panel</Link><template v-else><Link href="/register" class="hover:text-[#0b8f5a]">Crear cuenta</Link><Link href="/login" class="hover:text-[#0b8f5a]">Iniciar sesión</Link></template></div></div>
                    <div><p class="font-semibold text-[#10261f]">Legal</p><div class="mt-3 grid gap-2 text-[#64756d]"><Link href="/privacy" class="hover:text-[#0b8f5a]">Privacidad</Link><Link href="/terms" class="hover:text-[#0b8f5a]">Términos</Link></div></div>
                </div>
            </div>
            <div class="mx-auto max-w-7xl px-5 pb-8 sm:px-8 lg:px-10"><p class="text-sm text-[#64756d]">© {{ new Date().getFullYear() }} {{ appName }}. Hecho para equipos que atienden por WhatsApp.</p></div>
        </footer>
    </div>
</template>
