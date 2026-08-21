<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import type { DailyRow } from '@/features/analytics/analyticsTypes';
import { dateLabel } from '@/features/analytics/analyticsUtils';

const props = defineProps<{
  daily: DailyRow[];
}>();

const chartOptions = computed(() => ({
  chart: {
    id: 'message-volume',
    toolbar: { show: false },
    sparkline: { enabled: false },
    fontFamily: 'inherit',
  },
  xaxis: {
    categories: props.daily.map((d) => dateLabel(d.date)),
    labels: { style: { fontSize: '11px', colors: '#71717a' } },
  },
  yaxis: {
    labels: { style: { fontSize: '11px', colors: '#71717a' } },
  },
  colors: ['#10b981', '#6366f1'],
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { opacity: 0.15 },
  legend: { position: 'top' as const, horizontalAlign: 'left' as const, fontSize: '12px' },
  tooltip: { shared: true, intersect: false },
  grid: { borderColor: '#e4e4e7' },
}));

const series = computed(() => [
  {
    name: 'Entrantes',
    data: props.daily.map((d) => d.messages_inbound),
  },
  {
    name: 'Salientes',
    data: props.daily.map((d) => d.messages_outbound),
  },
]);
</script>

<template>
  <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
    <h3 class="text-sm font-semibold text-zinc-900">Mensajes por día</h3>
    <div class="mt-4" style="height: 300px">
      <VueApexCharts
        type="area"
        height="100%"
        :options="chartOptions"
        :series="series"
      />
    </div>
  </div>
</template>
