<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/Analytics/StatCard.vue';
import MessageVolumeChart from '@/Components/Analytics/MessageVolumeChart.vue';
import ConversationStatusChart from '@/Components/Analytics/ConversationStatusChart.vue';
import LeadStatusChart from '@/Components/Analytics/LeadStatusChart.vue';
import FlowPerformanceChart from '@/Components/Analytics/FlowPerformanceChart.vue';
import type { AnalyticsOverviewData, PresetKey } from '@/features/analytics/analyticsTypes';
import { fetchAnalyticsOverview } from '@/features/analytics/analyticsApi';
import {
  safeRate,
  formatDuration,
  formatNumber,
  getPresetRange,
  isValidRange,
  maxRangeDays,
  presetLabel,
  extractErrorMessage,
} from '@/features/analytics/analyticsUtils';

const page = usePage();
const user = page.props.auth.user;
const tenantId = page.props.auth.current_tenant_id;
const permissions = computed(() => page.props.auth.permissions);

const can = (perm: string): boolean => permissions.value.includes(perm);
const hasAccess = computed(() => can('analytics.view'));

const loading = ref(true);
const error = ref<string | null>(null);
const overview = ref<AnalyticsOverviewData | null>(null);

const activePreset = ref<PresetKey>('30d');
const customFrom = ref('');
const customTo = ref('');
const customError = ref<string | null>(null);

const buildParams = (): { from?: string; to?: string } => {
  if (activePreset.value === 'custom') {
    if (customFrom.value && customTo.value) {
      return { from: customFrom.value, to: customTo.value };
    }
    return {};
  }

  const range = getPresetRange(activePreset.value);
  return { from: range.from, to: range.to };
};

const load = async (): Promise<void> => {
  if (!tenantId) {
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const params = buildParams();
    overview.value = await fetchAnalyticsOverview(tenantId, params.from, params.to);
  } catch (err) {
    error.value = extractErrorMessage(err, 'No pudimos cargar las métricas.');
  } finally {
    loading.value = false;
  }
};

const selectPreset = (preset: PresetKey): void => {
  activePreset.value = preset;

  if (preset !== 'custom') {
    customError.value = null;
    load();
  }
};

const applyCustomRange = (): void => {
  customError.value = null;

  if (!customFrom.value || !customTo.value) {
    customError.value = 'Selecciona ambas fechas.';
    return;
  }

  if (!isValidRange({ from: customFrom.value, to: customTo.value })) {
    customError.value = 'La fecha de inicio debe ser anterior o igual a la fecha fin.';
    return;
  }

  if (!maxRangeDays({ from: customFrom.value, to: customTo.value })) {
    customError.value = 'El rango máximo es de 365 días.';
    return;
  }

  load();
};

const messagesTotal = computed(() => overview.value?.messages.total ?? 0);
const conversationsOpen = computed(() => overview.value?.conversations.open ?? 0);
const leadConversion = computed(() => {
  const o = overview.value;
  if (!o) return '0%';
  return `${safeRate(o.leads.won, o.leads.total)}%`;
});
const flowCompletion = computed(() => {
  const o = overview.value;
  if (!o) return '0%';
  return `${safeRate(o.flows.completed, o.flows.total)}%`;
});
const avgResponse = computed(() => {
  if (!overview.value) return null;
  return overview.value.conversations.avg_response_time_seconds;
});
const periodLabel = computed(() => {
  if (!overview.value) return '';
  const { from, to } = overview.value.period;
  return `${from} — ${to}`;
});

onMounted(load);
</script>

<template>
  <AppLayout :user="user">
    <div class="space-y-6">
      <div class="app-card p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-zinc-900">Dashboard</h2>
            <p class="mt-1 text-sm text-zinc-600">
              Resumen del rendimiento de tus conversaciones, automatizaciones y leads.
            </p>
          </div>
          <button
            type="button"
            :disabled="loading"
            class="app-button app-button--secondary self-start"
            @click="load"
          >
            {{ loading ? 'Cargando...' : 'Actualizar' }}
          </button>
        </div>
      </div>

      <div v-if="!hasAccess" class="app-card p-8 text-sm text-[#71877b]">
        No tienes permiso para ver analytics.
      </div>

      <template v-else>
        <div class="flex flex-wrap items-center gap-2">
          <button
            v-for="preset in (['7d', '30d', '90d'] as PresetKey[])"
            :key="preset"
            type="button"
            class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
            :class="activePreset === preset
              ? 'bg-[#10261f] text-white'
              : 'border border-[#cbdacf] text-[#33483e] hover:bg-[#f0f5ef]'"
            @click="selectPreset(preset)"
          >
            {{ presetLabel(preset) }}
          </button>
          <button
            type="button"
            class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
            :class="activePreset === 'custom'
              ? 'bg-[#10261f] text-white'
              : 'border border-[#cbdacf] text-[#33483e] hover:bg-[#f0f5ef]'"
            @click="activePreset = 'custom'"
          >
            Personalizado
          </button>
        </div>

        <div v-if="activePreset === 'custom'" class="flex flex-wrap items-end gap-3">
          <div>
            <label for="analytics-from" class="mb-1 block text-xs font-medium text-zinc-600">Desde</label>
            <input
              id="analytics-from"
              v-model="customFrom"
              type="date"
              class="app-field px-3 py-1.5"
            />
          </div>
          <div>
            <label for="analytics-to" class="mb-1 block text-xs font-medium text-zinc-600">Hasta</label>
            <input
              id="analytics-to"
              v-model="customTo"
              type="date"
              class="app-field px-3 py-1.5"
            />
          </div>
          <button
            type="button"
            class="app-button app-button--primary"
            @click="applyCustomRange"
          >
            Aplicar
          </button>
          <p v-if="customError" class="text-xs text-red-600">{{ customError }}</p>
        </div>

        <div v-if="overview && !loading" class="text-xs text-zinc-400">
          Datos agregados · {{ periodLabel }}
        </div>

        <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
          {{ error }}
          <button
            type="button"
            class="ml-2 underline hover:text-red-900"
            @click="load"
          >
            Reintentar
          </button>
        </div>

        <div v-if="loading" class="space-y-6">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="i in 4" :key="i" class="animate-pulse app-card p-6">
              <div class="h-4 w-24 rounded bg-zinc-200"></div>
              <div class="mt-3 h-8 w-16 rounded bg-zinc-200"></div>
            </div>
          </div>
          <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div v-for="i in 4" :key="i" class="animate-pulse app-card p-6">
              <div class="h-4 w-40 rounded bg-zinc-200"></div>
              <div class="mt-4 h-48 rounded bg-zinc-100"></div>
            </div>
          </div>
        </div>

        <template v-else-if="overview">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
              label="Mensajes totales"
              :value="formatNumber(messagesTotal)"
              :subtitle="`${formatNumber(overview.messages.inbound)} entrantes · ${formatNumber(overview.messages.outbound)} salientes`"
            />
            <StatCard
              label="Conversaciones activas"
              :value="String(conversationsOpen)"
              :subtitle="avgResponse !== null ? `Respuesta media: ${formatDuration(avgResponse)}` : undefined"
            />
            <StatCard
              label="Conversión de leads"
              :value="leadConversion"
              :subtitle="`${overview.leads.won} ganados de ${overview.leads.total}`"
            />
            <StatCard
              label="Finalización de flujos"
              :value="flowCompletion"
              :subtitle="`${overview.flows.completed} completados de ${overview.flows.total}`"
            />
          </div>

          <div v-if="overview.daily.length === 0" class="app-card p-8 text-center">
            <p class="text-sm text-zinc-500">Sin datos en el rango seleccionado.</p>
          </div>

          <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <MessageVolumeChart :daily="overview.daily" />
            <ConversationStatusChart :conversations="overview.conversations" />
            <LeadStatusChart :leads="overview.leads" />
            <FlowPerformanceChart :flows="overview.flows" />
          </div>
        </template>
      </template>
    </div>
  </AppLayout>
</template>
