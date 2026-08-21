<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import type { ConversationsStats } from '@/features/analytics/analyticsTypes';

const props = defineProps<{
  conversations: ConversationsStats;
}>();

const chartOptions = computed(() => ({
  chart: {
    id: 'conversation-status',
    type: 'donut' as const,
    toolbar: { show: false },
    fontFamily: 'inherit',
  },
  labels: ['Abiertas', 'Resueltas', 'Archivadas'],
  colors: ['#f59e0b', '#10b981', '#a1a1aa'],
  legend: { position: 'bottom' as const, fontSize: '12px' },
  plotOptions: {
    pie: {
      donut: {
        size: '65%',
        labels: {
          show: true,
          total: { show: true, label: 'Total', formatter: () => String(props.conversations.total) },
        },
      },
    },
  },
  tooltip: { enabled: true },
}));

const series = computed(() => [
  props.conversations.open,
  props.conversations.resolved,
  props.conversations.archived,
]);
</script>

<template>
  <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
    <h3 class="text-sm font-semibold text-zinc-900">Estado de conversaciones</h3>
    <div class="mt-4 flex justify-center" style="height: 300px">
      <VueApexCharts
        type="donut"
        height="100%"
        width="100%"
        :options="chartOptions"
        :series="series"
      />
    </div>
  </div>
</template>
