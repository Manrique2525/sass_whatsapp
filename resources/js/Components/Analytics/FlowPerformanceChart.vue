<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import type { FlowsStats } from '@/features/analytics/analyticsTypes';

const props = defineProps<{
  flows: FlowsStats;
}>();

const chartOptions = computed(() => ({
  chart: {
    id: 'flow-performance',
    toolbar: { show: false },
    fontFamily: 'inherit',
  },
  xaxis: {
    categories: ['Completados', 'Fallidos'],
    labels: { style: { fontSize: '11px', colors: '#71717a' } },
  },
  yaxis: {
    labels: { style: { fontSize: '11px', colors: '#71717a' }, forceNiceScale: true },
  },
  colors: ['#10b981', '#ef4444'],
  plotOptions: {
    bar: { borderRadius: 4, columnWidth: '50%' },
  },
  legend: { show: false },
  grid: { borderColor: '#e4e4e7' },
  tooltip: { enabled: true },
}));

const series = computed(() => [
  {
    name: 'Flujos',
    data: [props.flows.completed, props.flows.failed],
  },
]);
</script>

<template>
  <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
    <h3 class="text-sm font-semibold text-zinc-900">Rendimiento de flujos</h3>
    <div class="mt-4" style="height: 300px">
      <VueApexCharts
        type="bar"
        height="100%"
        :options="chartOptions"
        :series="series"
      />
    </div>
  </div>
</template>
