<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MarketingLayout from '@/Layouts/MarketingLayout.vue';
import Reveal from '@/Components/Marketing/Reveal.vue';

interface FreePlan {
    name: string;
    slug: string;
    limits: {
        messages: number | null;
        contacts: number | null;
        flowExecutions: number | null;
        users: number | null;
        knowledgeDocuments: number | null;
    };
    aiIncluded: boolean;
}

const props = defineProps<{ freePlan: FreePlan }>();
const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth.user));
const planLimitItems = computed(() => [
    { limit: props.freePlan.limits.messages, label: 'mensajes' },
    { limit: props.freePlan.limits.contacts, label: 'contactos' },
    { limit: props.freePlan.limits.flowExecutions, label: 'ejecuciones de flows' },
    { limit: props.freePlan.limits.users, label: 'usuarios' },
    { limit: props.freePlan.limits.knowledgeDocuments, label: 'documentos de conocimiento' },
]);

const benefits = [
    { number: '01', title: 'Responde más rápido', copy: 'Todo el contexto de cada conversación, listo para que tu equipo actúe sin buscar en cinco herramientas.' },
    { number: '02', title: 'Automatiza lo repetitivo', copy: 'Diseña flujos visuales para las preguntas y tareas que ocurren todos los días.' },
    { number: '03', title: 'No pierdas oportunidades', copy: 'Convierte conversaciones en leads, asigna seguimiento y mantén cada oportunidad visible.' },
];

const useCases = ['Ventas', 'Atención al cliente', 'Ecommerce', 'Clínicas', 'Inmobiliarias', 'Restaurantes', 'Agencias', 'Servicios'];

const faqs = [
    ['¿Puedo empezar gratis?', 'Sí. El plan Free te permite comenzar a organizar tu atención sin añadir datos de pago.'],
    ['¿Necesito tarjeta?', 'No. Puedes crear tu cuenta y comenzar con el plan Free sin introducir una tarjeta.'],
    ['¿La IA está incluida en el plan gratuito?', 'No. La IA no está incluida en el plan Free; el plan gratuito se centra en inbox, automatizaciones, contactos y conocimiento básico.'],
    ['¿Funciona con WhatsApp Business?', 'Sí. Conecta tu WhatsApp Business mediante la integración oficial de WhatsApp Business Cloud API.'],
    ['¿Puedo tener varios agentes?', 'Sí. El inbox compartido permite que tu equipo atienda conversaciones con roles y permisos por negocio.'],
    ['¿Puedo automatizar respuestas?', 'Sí. Crea flujos visuales con condiciones, respuestas, FAQs e IA sin tener que programar.'],
    ['¿Puedo pasar una conversación a un humano?', 'Sí. Un agente puede tomar el control cuando una conversación necesita atención personal.'],
    ['¿Necesito programar?', 'No. El Flow Builder visual te permite construir automatizaciones con nodos y conexiones.'],
    ['¿Puedo medir resultados?', 'Sí. Consulta volumen de mensajes, conversaciones, tiempos de respuesta y actividad de tus equipos.'],
];
</script>

<template>
    <MarketingLayout>
        <Head>
            <meta name="robots" content="index,follow" />
        </Head>

        <section id="inicio" class="relative isolate overflow-hidden bg-[#10261f] pt-32 text-white sm:pt-40">
            <div class="landing-orb landing-orb--lime" aria-hidden="true"></div>
            <div class="landing-orb landing-orb--teal" aria-hidden="true"></div>
            <div class="mx-auto grid max-w-7xl items-center gap-14 px-5 pb-20 sm:px-8 lg:grid-cols-[0.9fr_1.1fr] lg:gap-10 lg:px-10 lg:pb-28">
                <Reveal>
                    <div class="relative z-10 max-w-xl">
                        <p class="mb-6 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] text-[#b7f36b]">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#b7f36b]"></span>
                            Automatización y atención para WhatsApp
                        </p>
                        <h1 class="max-w-2xl text-4xl font-semibold leading-[1.02] tracking-[-0.055em] sm:text-6xl lg:text-[clamp(3.6rem,5.3vw,5.5rem)]">
                            Automatiza WhatsApp, organiza tus conversaciones y convierte más clientes.
                        </h1>
                        <p class="mt-7 max-w-lg text-base leading-7 text-[#c5d3cb] sm:text-lg">
                            Centraliza a todo tu equipo en un inbox compartido, automatiza respuestas con flujos e IA y da seguimiento a cada oportunidad desde una sola plataforma.
                        </p>
                        <div class="mt-9 flex flex-wrap items-center gap-4">
                            <Link v-if="isAuthenticated" href="/dashboard" class="inline-flex items-center gap-3 rounded-full bg-[#b7f36b] px-6 py-3.5 text-sm font-bold text-[#10261f] shadow-[0_12px_30px_rgba(183,243,107,0.18)] transition hover:-translate-y-0.5 hover:bg-[#c9fa8c] focus:outline-none focus:ring-2 focus:ring-[#b7f36b] focus:ring-offset-2 focus:ring-offset-[#10261f]">
                                Ir al panel <span aria-hidden="true">↗</span>
                            </Link>
                            <Link v-else href="/register" class="inline-flex items-center gap-3 rounded-full bg-[#b7f36b] px-6 py-3.5 text-sm font-bold text-[#10261f] shadow-[0_12px_30px_rgba(183,243,107,0.18)] transition hover:-translate-y-0.5 hover:bg-[#c9fa8c] focus:outline-none focus:ring-2 focus:ring-[#b7f36b] focus:ring-offset-2 focus:ring-offset-[#10261f]">
                                Empezar gratis <span aria-hidden="true">↗</span>
                            </Link>
                            <a href="#producto" class="inline-flex items-center gap-2 rounded-full border border-white/20 px-6 py-3.5 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:border-white/50 focus:outline-none focus:ring-2 focus:ring-white">
                                Ver cómo funciona <span aria-hidden="true">↓</span>
                            </a>
                        </div>
                        <p class="mt-5 text-xs text-[#91a69a]">Plan gratuito para empezar. Sin tarjeta ni promesas de prueba temporal.</p>
                    </div>
                </Reveal>

                <Reveal :delay="140">
                    <div id="producto" class="relative z-10 lg:translate-x-4">
                        <div class="absolute -right-2 -top-8 hidden rounded-2xl border border-white/15 bg-[#203c31]/90 px-4 py-3 text-xs shadow-2xl backdrop-blur sm:block">
                            <span class="mr-2 inline-block h-2 w-2 rounded-full bg-[#b7f36b]"></span> 3 agentes activos
                        </div>
                        <div class="overflow-hidden rounded-[1.5rem] border border-white/15 bg-[#f7f8f3] shadow-[0_30px_80px_rgba(0,0,0,0.35)]">
                            <div class="flex items-center justify-between border-b border-[#dce5dd] px-4 py-3 text-[#64756d]">
                                <div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-[#ff8378]"></span><span class="h-2 w-2 rounded-full bg-[#f4c95d]"></span><span class="h-2 w-2 rounded-full bg-[#85d59c]"></span></div>
                                <span class="text-[10px] font-semibold uppercase tracking-[0.16em]">Inbox / Hoy</span>
                                <span class="h-5 w-5 rounded-full bg-[#dce9de]"></span>
                            </div>
                            <div class="grid min-h-[310px] grid-cols-[0.8fr_1.2fr] text-[#10261f] sm:min-h-[390px]">
                                <div class="border-r border-[#dce5dd] bg-[#eef3ed] p-3 sm:p-5">
                                    <div class="mb-5 flex items-center justify-between"><span class="text-xs font-bold">Conversaciones</span><span class="rounded-md bg-[#10261f] px-1.5 py-1 text-[9px] text-white">12</span></div>
                                    <div class="space-y-2">
                                        <div class="rounded-xl bg-white p-2.5 shadow-sm ring-1 ring-[#b7f36b] sm:p-3"><div class="flex items-center justify-between"><span class="text-[10px] font-bold sm:text-xs">Mariana López</span><span class="text-[8px] text-[#0b8f5a]">Ahora</span></div><p class="mt-1 truncate text-[9px] text-[#71847a]">¿Me pueden ayudar con...</p></div>
                                        <div class="rounded-xl p-2.5 sm:p-3"><div class="flex items-center justify-between"><span class="text-[10px] font-semibold sm:text-xs">Carlos Méndez</span><span class="text-[8px] text-[#71847a]">10:42</span></div><p class="mt-1 truncate text-[9px] text-[#71847a]">Gracias por la información</p></div>
                                        <div class="rounded-xl p-2.5 sm:p-3"><div class="flex items-center justify-between"><span class="text-[10px] font-semibold sm:text-xs">Laura Torres</span><span class="text-[8px] text-[#71847a]">09:18</span></div><p class="mt-1 truncate text-[9px] text-[#71847a]">Quiero conocer el servicio</p></div>
                                    </div>
                                </div>
                                <div class="flex flex-col bg-white p-4 sm:p-6">
                                    <div class="flex items-center justify-between border-b border-[#eef1ed] pb-3"><div><p class="text-xs font-bold sm:text-sm">Mariana López</p><p class="text-[9px] text-[#0b8f5a]">● En línea · asignada a Ana</p></div><span class="rounded-full bg-[#edf8e1] px-2 py-1 text-[9px] font-semibold text-[#4b7d34]">Lead nuevo</span></div>
                                    <div class="flex flex-1 flex-col justify-end gap-3 py-5 text-[10px] leading-4 sm:text-xs"><div class="max-w-[85%] self-start rounded-2xl rounded-bl-sm bg-[#eef3ed] px-3 py-2.5">Hola, vi su catálogo y tengo una pregunta.</div><div class="max-w-[85%] self-end rounded-2xl rounded-br-sm bg-[#10261f] px-3 py-2.5 text-white">¡Hola, Mariana! Te ayudamos con gusto. ¿Qué producto te interesa?</div><div class="flex items-center gap-2 text-[9px] text-[#71847a]"><span class="h-1.5 w-1.5 rounded-full bg-[#b7f36b]"></span> Flujo de bienvenida completado</div></div>
                                    <div class="rounded-xl border border-[#dce5dd] px-3 py-2 text-[10px] text-[#9aaba1]">Escribe una respuesta...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Reveal>
            </div>
            <div class="relative mx-auto max-w-7xl px-5 pb-8 sm:px-8 lg:px-10"><p class="text-xs font-medium uppercase tracking-[0.18em] text-[#789184]">Una operación más clara para negocios que conversan</p></div>
        </section>

        <section class="border-b border-[#dce5dd] bg-[#f7f8f3] py-20 sm:py-28">
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10"><Reveal><div class="max-w-2xl"><p class="eyebrow">Menos ruido. Más avance.</p><h2 class="section-title">Tu equipo ya conversa con clientes. Ahora puede hacerlo mejor.</h2></div></Reveal><div class="mt-14 grid gap-px overflow-hidden rounded-3xl border border-[#dce5dd] bg-[#dce5dd] md:grid-cols-3"><Reveal v-for="(benefit, index) in benefits" :key="benefit.number" :delay="index * 100"><article class="h-full bg-[#f7f8f3] p-7 sm:p-9"><span class="text-xs font-bold text-[#0b8f5a]">{{ benefit.number }}</span><h3 class="mt-16 text-xl font-semibold tracking-tight">{{ benefit.title }}</h3><p class="mt-3 text-sm leading-6 text-[#64756d]">{{ benefit.copy }}</p></article></Reveal></div></div>
        </section>

        <section id="funciones" class="bg-[#e9f1e7] py-20 sm:py-28">
            <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10"><Reveal><p class="eyebrow">Una plataforma, cada momento</p><h2 class="section-title max-w-3xl">De la primera pregunta al siguiente paso.</h2></Reveal><div class="mt-14 grid gap-5 lg:grid-cols-2">
                <Reveal><article class="feature-card feature-card--dark"><div><span class="feature-index">01 / INBOX COMPARTIDO</span><h3 class="feature-title">Todo tu equipo, una sola conversación.</h3><p class="feature-copy">Comparte el contexto, asigna conversaciones y responde en tiempo real. Cuando una conversación necesita una persona, el equipo toma el control sin perder el hilo.</p></div><div class="mini-inbox"><div class="mb-3 flex justify-between text-[10px] text-[#92aaa0]"><span>Equipo de atención</span><span class="text-[#b7f36b]">● online</span></div><div class="flex -space-x-2"><span class="avatar bg-[#e9a78e]">AL</span><span class="avatar bg-[#8fb5d9]">CM</span><span class="avatar bg-[#bea4dc]">RT</span><span class="avatar bg-[#b7f36b] text-[#10261f]">+4</span></div><div class="mt-5 rounded-xl bg-white/10 p-3 text-xs text-[#d1e0d6]">6 conversaciones asignadas <span class="float-right text-[#b7f36b]">→</span></div></div></article></Reveal>
                <Reveal :delay="100"><article class="feature-card"><div><span class="feature-index">02 / FLOW BUILDER</span><h3 class="feature-title">Automatiza sin perder el toque humano.</h3><p class="feature-copy">Construye rutas visuales para mensajes, condiciones, FAQs, IA y handoff. Tu operación se adapta a tu negocio, no al revés.</p></div><div class="flow-line"><span class="flow-node">Mensaje</span><span class="flow-connector"></span><span class="flow-node">Condición</span><span class="flow-connector"></span><span class="flow-node flow-node--lime">IA / FAQ</span><span class="flow-connector"></span><span class="flow-node flow-node--dark">Humano</span></div></article></Reveal>
                <Reveal><article class="feature-card lg:col-span-2"><div class="grid gap-8 lg:grid-cols-[0.7fr_1.3fr] lg:items-end"><div><span class="feature-index">03 / CONOCIMIENTO + IA</span><h3 class="feature-title">Responde usando el conocimiento de tu negocio.</h3><p class="feature-copy">Carga tus documentos y FAQs para dar respuestas más útiles y consistentes. Si hace falta, un agente continúa la conversación.</p></div><div class="knowledge-panel"><div class="flex items-center justify-between border-b border-[#dce5dd] pb-3"><span class="text-xs font-bold">Base de conocimiento</span><span class="text-[10px] text-[#0b8f5a]">Actualizada</span></div><div class="mt-4 grid gap-2 sm:grid-cols-3"><span class="knowledge-pill">Preguntas frecuentes</span><span class="knowledge-pill">Catálogo 2026</span><span class="knowledge-pill">Políticas de envío</span></div><div class="mt-5 rounded-xl bg-[#10261f] p-4 text-xs leading-5 text-[#d5e5d9]">“La respuesta se construyó con la información de tu negocio.” <span class="mt-2 block text-[10px] text-[#b7f36b]">Respuesta contextual · lista para revisión</span></div></div></div></article></Reveal>
            </div></div>
        </section>

        <section class="bg-[#f7f8f3] py-20 sm:py-28"><div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10"><div class="grid gap-8 lg:grid-cols-3"><Reveal><div class="lg:col-span-1"><p class="eyebrow">Cuando la conversación avanza</p><h2 class="section-title">Del chat al crecimiento.</h2></div></Reveal><Reveal :delay="100"><article class="border-t border-[#bfcfc3] pt-5"><span class="feature-index">04 / LEADS</span><h3 class="mt-10 text-2xl font-semibold tracking-tight">No dejes oportunidades perdidas en el chat.</h3><p class="mt-3 text-sm leading-6 text-[#64756d]">Identifica leads, organiza estados y da seguimiento a cada oportunidad desde la conversación.</p><div class="mt-7 rounded-2xl bg-[#edf4eb] p-4"><div class="flex items-center gap-3"><span class="avatar bg-[#e9a78e]">ML</span><div><p class="text-xs font-bold">Mariana López</p><p class="text-[10px] text-[#0b8f5a]">Lead · En seguimiento</p></div><span class="ml-auto text-lg text-[#0b8f5a]">→</span></div></div></article></Reveal><Reveal :delay="180"><article class="border-t border-[#bfcfc3] pt-5"><span class="feature-index">05 / ANALYTICS</span><h3 class="mt-10 text-2xl font-semibold tracking-tight">Mide lo que funciona.</h3><p class="mt-3 text-sm leading-6 text-[#64756d]">Entiende el volumen de mensajes, tus conversaciones y el tiempo de respuesta de tu equipo.</p><div class="mt-7 rounded-2xl bg-[#10261f] p-4 text-white"><div class="flex justify-between text-[10px] text-[#9db2a4]"><span>Tiempo de respuesta</span><span class="text-[#b7f36b]">Este mes</span></div><p class="mt-3 text-3xl font-semibold tracking-tight">12<span class="text-lg text-[#b7f36b]">m</span></p><div class="mt-3 flex h-8 items-end gap-1"><span v-for="height in [28,42,34,60,50,70,64,88,72,96]" :key="height" class="flex-1 rounded-t bg-[#b7f36b]/70" :style="{ height: `${height}%` }"></span></div></div></article></Reveal></div></div></section>

        <section id="como-funciona" class="bg-[#10261f] py-20 text-white sm:py-28"><div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10"><Reveal><div class="max-w-2xl"><p class="eyebrow eyebrow--dark">Empieza con lo esencial</p><h2 class="section-title section-title--dark">Tres pasos para atender con más claridad.</h2></div></Reveal><div class="mt-14 grid gap-10 border-t border-white/15 pt-8 md:grid-cols-3"><Reveal v-for="(step, index) in ['Crea tu espacio de trabajo', 'Conecta WhatsApp Business', 'Automatiza, atiende y convierte']" :key="step" :delay="index * 100"><div><span class="text-sm font-bold text-[#b7f36b]">0{{ index + 1 }}</span><h3 class="mt-12 text-xl font-semibold tracking-tight">{{ step }}</h3><p class="mt-3 text-sm leading-6 text-[#9db2a4]">{{ ['Tu equipo empieza con un lugar ordenado para cada conversación.', 'Lleva tu canal de atención a la plataforma con la integración oficial.', 'Combina automatizaciones y personas para dar la respuesta correcta.'][index] }}</p></div></Reveal></div></div></section>

        <section class="bg-[#f7f8f3] py-20 sm:py-28"><div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10"><Reveal><p class="eyebrow">Para equipos con conversaciones reales</p><h2 class="section-title max-w-3xl">Una base flexible para tu forma de trabajar.</h2></Reveal><div class="mt-12 flex flex-wrap gap-3"><span v-for="useCase in useCases" :key="useCase" class="rounded-full border border-[#cbd8cf] px-5 py-3 text-sm font-medium text-[#33483e] transition hover:-translate-y-0.5 hover:border-[#0b8f5a] hover:bg-white">{{ useCase }}</span></div></div></section>

        <section id="plan" class="bg-[#10261f] py-20 text-white sm:py-28"><div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10"><div class="grid gap-12 lg:grid-cols-[0.75fr_1.25fr] lg:items-center"><Reveal><div><p class="eyebrow eyebrow--dark">Un comienzo claro</p><h2 class="section-title section-title--dark">Empieza gratis, con límites transparentes.</h2><p class="mt-5 max-w-md text-base leading-7 text-[#a9beb0]">Un plan para comenzar a organizar y automatizar tu atención por WhatsApp, sin añadir complejidad desde el primer día.</p></div></Reveal><Reveal :delay="120"><article class="rounded-[1.75rem] border border-[#b7f36b]/40 bg-[#f7f8f3] p-7 text-[#10261f] shadow-[0_25px_70px_rgba(0,0,0,0.18)] sm:p-9"><div class="flex flex-wrap items-start justify-between gap-4"><div><span class="inline-flex rounded-full bg-[#e0f2c9] px-3 py-1 text-[10px] font-bold uppercase tracking-[0.16em] text-[#4b7d34]">Plan gratuito</span><h3 class="mt-4 text-3xl font-semibold tracking-[-0.04em]">{{ props.freePlan.name }}</h3></div><span class="text-sm font-semibold text-[#0b8f5a]">Para comenzar</span></div><p class="mt-4 max-w-lg text-sm leading-6 text-[#64756d]">Organiza tu equipo y tus primeras conversaciones con los límites reales del plan.</p><ul class="mt-7 grid gap-3 sm:grid-cols-2" aria-label="Límites del plan Free"><li v-for="item in planLimitItems" :key="item.label" class="flex items-baseline gap-2 rounded-xl bg-[#eef3ed] px-3 py-2.5 text-sm"><span class="font-bold">Hasta {{ item.limit }}</span><span class="text-[#64756d]">{{ item.label }}</span></li></ul><p class="mt-5 rounded-xl border border-[#ead8a7] bg-[#fff8df] px-3 py-2.5 text-xs leading-5 text-[#765d25]">La IA no está incluida en el plan Free.</p><Link v-if="isAuthenticated" href="/dashboard" class="marketing-button mt-7 w-full">Ir al panel <span aria-hidden="true">↗</span></Link><Link v-else href="/register" class="marketing-button mt-7 w-full">Empezar gratis <span aria-hidden="true">↗</span></Link></article></Reveal></div></div></section>

        <section id="seguridad" class="border-y border-[#dce5dd] bg-[#eef3ed] py-20 sm:py-28"><div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10"><div class="grid gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:items-start"><Reveal><div><p class="eyebrow">Diseñado para confiar</p><h2 class="section-title">Tu negocio merece control, no cajas negras.</h2></div></Reveal><Reveal :delay="120"><div class="grid gap-x-8 gap-y-10 sm:grid-cols-2"><div><span class="trust-icon">↗</span><h3 class="mt-4 font-semibold">Datos aislados por negocio</h3><p class="mt-2 text-sm leading-6 text-[#64756d]">Cada espacio mantiene sus conversaciones y recursos separados.</p></div><div><span class="trust-icon">⌁</span><h3 class="mt-4 font-semibold">Roles y permisos</h3><p class="mt-2 text-sm leading-6 text-[#64756d]">Define qué puede ver y hacer cada persona de tu equipo.</p></div><div><span class="trust-icon">◈</span><h3 class="mt-4 font-semibold">Tokens cifrados</h3><p class="mt-2 text-sm leading-6 text-[#64756d]">Las credenciales de integraciones se protegen en el servidor.</p></div><div><span class="trust-icon">✓</span><h3 class="mt-4 font-semibold">Auditoría y webhooks seguros</h3><p class="mt-2 text-sm leading-6 text-[#64756d]">Trazabilidad de acciones y validaciones para eventos entrantes.</p></div></div></Reveal></div></div></section>

        <section class="bg-[#e0f2c9] py-16 sm:py-20"><div class="mx-auto flex max-w-7xl flex-col gap-8 px-5 sm:px-8 md:flex-row md:items-center md:justify-between lg:px-10"><Reveal><div><p class="eyebrow text-[#4b7d34]">Empieza sin complicarte</p><h2 class="mt-3 text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Un plan gratuito para dar el primer paso.</h2><p class="mt-3 max-w-xl text-sm leading-6 text-[#4b6548]">Crea tu espacio, organiza tu equipo y descubre una forma más clara de atender por WhatsApp.</p></div></Reveal><Reveal :delay="100"><Link v-if="isAuthenticated" href="/dashboard" class="marketing-button whitespace-nowrap">Ir al panel <span aria-hidden="true">↗</span></Link><Link v-else href="/register" class="marketing-button whitespace-nowrap">Empezar gratis <span aria-hidden="true">↗</span></Link></Reveal></div></section>

        <section id="faq" class="bg-[#f7f8f3] py-20 sm:py-28"><div class="mx-auto max-w-3xl px-5 sm:px-8"><Reveal><p class="eyebrow">Preguntas frecuentes</p><h2 class="section-title">Lo importante, sin letra pequeña.</h2></Reveal><div class="mt-10 divide-y divide-[#dce5dd] border-y border-[#dce5dd]"><details v-for="faq in faqs" :key="faq[0]" class="group py-5"><summary class="flex cursor-pointer list-none items-center justify-between gap-5 text-base font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-[#10261f]">{{ faq[0] }}<span class="text-xl font-normal text-[#0b8f5a] transition group-open:rotate-45">+</span></summary><p class="max-w-2xl pt-3 text-sm leading-6 text-[#64756d]">{{ faq[1] }}</p></details></div></div></section>

        <section class="bg-[#10261f] px-5 py-20 text-center text-white sm:px-8 sm:py-28"><Reveal><p class="eyebrow eyebrow--dark">Tu próxima conversación empieza aquí</p><h2 class="mx-auto mt-4 max-w-3xl text-4xl font-semibold tracking-[-0.055em] sm:text-6xl">Haz que cada mensaje cuente.</h2><p class="mx-auto mt-5 max-w-lg text-base leading-7 text-[#a9beb0]">Organiza tu atención, automatiza lo que puedas y deja que tu equipo se concentre en lo que importa.</p><Link v-if="isAuthenticated" href="/dashboard" class="mt-9 inline-flex items-center gap-3 rounded-full bg-[#b7f36b] px-7 py-3.5 text-sm font-bold text-[#10261f] transition hover:-translate-y-0.5 hover:bg-[#c9fa8c] focus:outline-none focus:ring-2 focus:ring-[#b7f36b] focus:ring-offset-2 focus:ring-offset-[#10261f]">Ir al panel <span aria-hidden="true">↗</span></Link><Link v-else href="/register" class="mt-9 inline-flex items-center gap-3 rounded-full bg-[#b7f36b] px-7 py-3.5 text-sm font-bold text-[#10261f] transition hover:-translate-y-0.5 hover:bg-[#c9fa8c] focus:outline-none focus:ring-2 focus:ring-[#b7f36b] focus:ring-offset-2 focus:ring-offset-[#10261f]">Empezar gratis <span aria-hidden="true">↗</span></Link></Reveal></section>
    </MarketingLayout>
</template>

<style scoped>
@reference "../../css/app.css";

.eyebrow { @apply text-xs font-bold uppercase tracking-[0.18em] text-[#0b8f5a]; }
.eyebrow--dark { @apply text-[#b7f36b]; }
.section-title { @apply mt-4 text-4xl font-semibold leading-[1.05] tracking-[-0.05em] text-[#10261f] sm:text-5xl; }
.section-title--dark { @apply text-white; }
.feature-card { @apply flex min-h-[390px] flex-col justify-between overflow-hidden rounded-3xl border border-[#d0ddd2] bg-[#f7f8f3] p-7 sm:p-10; }
.feature-card--dark { @apply border-[#2c4b3d] bg-[#10261f] text-white; }
.feature-index { @apply text-[10px] font-bold uppercase tracking-[0.17em] text-[#0b8f5a]; }
.feature-card--dark .feature-index { @apply text-[#b7f36b]; }
.feature-title { @apply mt-12 max-w-md text-2xl font-semibold leading-tight tracking-[-0.04em]; }
.feature-copy { @apply mt-4 max-w-md text-sm leading-6 text-[#64756d]; }
.feature-card--dark .feature-copy { @apply text-[#a9beb0]; }
.mini-inbox { @apply mt-10 rounded-2xl border border-white/10 bg-white/5 p-4; }
.avatar { @apply inline-flex h-7 w-7 items-center justify-center rounded-full text-[8px] font-bold text-white ring-2 ring-[#10261f]; }
.flow-line { @apply mt-12 flex flex-wrap items-center gap-2; }
.flow-node { @apply rounded-lg border border-[#cbd8cf] bg-white px-3 py-2 text-[10px] font-semibold text-[#33483e]; }
.flow-node--lime { @apply border-[#b7f36b] bg-[#e0f2c9] text-[#4b7d34]; }
.flow-node--dark { @apply border-[#10261f] bg-[#10261f] text-white; }
.flow-connector { @apply h-px w-3 bg-[#afc1b4]; }
.knowledge-panel { @apply rounded-2xl border border-[#dce5dd] bg-[#eef3ed] p-5; }
.knowledge-pill { @apply rounded-lg border border-[#d3e0d5] bg-white px-3 py-2 text-[10px] font-medium text-[#50665a]; }
.trust-icon { @apply inline-flex h-9 w-9 items-center justify-center rounded-xl bg-[#10261f] text-lg text-[#b7f36b]; }
.landing-orb { @apply pointer-events-none absolute rounded-full blur-3xl; }
.landing-orb--lime { @apply -right-32 top-28 h-80 w-80 bg-[#b7f36b]/10; }
.landing-orb--teal { @apply -left-40 bottom-0 h-96 w-96 bg-[#0b8f5a]/15; }
</style>
