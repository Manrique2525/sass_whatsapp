<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

interface PublicPlan {
    slug: string;
    name: string;
    description: string | null;
    priceMonthly: string;
    priceYearly: string;
    limits: Record<string, number | null>;
    aiIncluded: boolean;
}

const props = defineProps<{
    plans: PublicPlan[];
    isAuthenticated: boolean;
}>();

const yearly = ref(false);
const currency = new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });

const visiblePlans = computed(() => props.plans.filter((plan) => plan.slug !== 'free'));

const price = (plan: PublicPlan): string => {
    const value = Number(yearly.value ? plan.priceYearly : plan.priceMonthly);
    return value === 0 ? 'Gratis' : currency.format(value);
};

const limitLabel = (value: number | null): string => value === null ? 'Sin límite' : `Hasta ${value.toLocaleString('es-ES')}`;
</script>

<template>
    <section id="precios" class="border-y border-[#dce5dd] bg-[#f7f8f3] py-20 sm:py-28">
        <div class="mx-auto max-w-7xl px-5 sm:px-8 lg:px-10">
            <div class="flex flex-col justify-between gap-8 md:flex-row md:items-end">
                <div class="max-w-2xl">
                    <p class="eyebrow">Planes que crecen contigo</p>
                    <h2 class="section-title">Elige el ritmo de tu operación.</h2>
                    <p class="mt-5 text-base leading-7 text-[#64756d]">Precios claros y límites visibles. Cambia de plan cuando tu equipo lo necesite.</p>
                </div>
                <div class="inline-flex self-start rounded-full border border-[#cbd8cf] bg-white p-1 text-sm md:self-auto" aria-label="Periodicidad del precio">
                    <button type="button" class="rounded-full px-4 py-2 transition" :class="!yearly ? 'bg-[#10261f] text-white' : 'text-[#64756d]'" :aria-pressed="!yearly" @click="yearly = false">Mensual</button>
                    <button type="button" class="rounded-full px-4 py-2 transition" :class="yearly ? 'bg-[#10261f] text-white' : 'text-[#64756d]'" :aria-pressed="yearly" @click="yearly = true">Anual</button>
                </div>
            </div>

            <div class="mt-12 grid gap-5" :class="visiblePlans.length > 2 ? 'lg:grid-cols-3' : 'md:grid-cols-2'">
                <article v-for="(plan, index) in visiblePlans" :key="plan.slug" class="flex flex-col rounded-3xl border border-[#d0ddd2] bg-white p-6 shadow-[0_12px_35px_rgba(16,38,31,0.05)] sm:p-8" :class="index === 1 ? 'border-[#0b8f5a] ring-2 ring-[#b7f36b]/50' : ''">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span v-if="index === 1" class="mb-3 inline-flex rounded-full bg-[#e0f2c9] px-3 py-1 text-[10px] font-bold uppercase tracking-[0.16em] text-[#4b7d34]">Más elegido</span>
                            <h3 class="text-2xl font-semibold tracking-[-0.04em] text-[#10261f]">{{ plan.name }}</h3>
                        </div>
                        <span class="rounded-full bg-[#eef3ed] px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-[#4b7d34]">{{ plan.slug }}</span>
                    </div>
                    <p class="mt-3 min-h-12 text-sm leading-6 text-[#64756d]">{{ plan.description || 'Herramientas para atender mejor cada conversación.' }}</p>
                    <p class="mt-7 text-4xl font-semibold tracking-[-0.05em] text-[#10261f]">{{ price(plan) }}<span v-if="Number(yearly ? plan.priceYearly : plan.priceMonthly) > 0" class="ml-1 text-sm font-medium text-[#71877b]">/{{ yearly ? 'año' : 'mes' }}</span></p>
                    <p v-if="yearly && Number(plan.priceMonthly) > 0" class="mt-1 text-xs text-[#0b8f5a]">Facturación anual</p>
                    <ul class="mt-7 grid gap-3 border-t border-[#edf2ec] pt-6 text-sm" :aria-label="`Incluido en ${plan.name}`">
                        <li v-for="item in [{ key: 'messages', label: 'mensajes' }, { key: 'contacts', label: 'contactos' }, { key: 'users', label: 'usuarios' }]" :key="item.key" class="flex items-center gap-2 text-[#33483e]"><span class="text-[#0b8f5a]">✓</span>{{ limitLabel(plan.limits[item.key]) }} {{ item.label }}</li>
                        <li class="flex items-center gap-2 text-[#33483e]"><span class="text-[#0b8f5a]">✓</span>{{ plan.aiIncluded ? 'IA incluida' : 'Automatizaciones visuales' }}</li>
                    </ul>
                    <Link :href="isAuthenticated ? '/dashboard' : '/register'" class="marketing-button mt-8 w-full">{{ isAuthenticated ? 'Ir al panel' : 'Empezar ahora' }} <span aria-hidden="true">↗</span></Link>
                </article>
                <div v-if="visiblePlans.length === 0" class="rounded-3xl border border-dashed border-[#cbd8cf] bg-white p-8 text-sm text-[#64756d]">El catálogo de planes de pago estará disponible próximamente. Puedes empezar con el plan gratuito.</div>
            </div>
        </div>
    </section>
</template>

<style scoped>
@reference "../../../css/app.css";
.eyebrow { @apply text-xs font-bold uppercase tracking-[0.18em] text-[#0b8f5a]; }
.section-title { @apply mt-4 text-4xl font-semibold leading-[1.05] tracking-[-0.05em] text-[#10261f] sm:text-5xl; }
</style>
