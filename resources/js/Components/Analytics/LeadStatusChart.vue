<script setup lang="ts">
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import type { LeadsStats } from '@/features/analytics/analyticsTypes';

const props = defineProps<{
  leads: LeadsStats;
}>();

const chartOptions = computed(() => ({
  chart: {
    id: 'lead-status',
    toolbar: { show: false },
    fontFamily: 'inherit',
  },
  xaxis: {
    categories: ['Nuevos', 'Ganados', 'Perdidos'],
    labels: { style: { fontSize: '11px', colors: '#71717a' } },
  },
  yaxis: {
    labels: { style: { fontSize: '11px', colors: '#71717a' }, forceNiceScale: true },
  },
  colors: ['#6366f1', '#10b981', '#ef4444'],
  plotOptions: {
    bar: { borderRadius: 4, columnWidth: '50%' },
  },
  legend: { show: false },
  grid: { borderColor: '#e4e4e7' },
  tooltip: { enabled: true },
}));

const series = computed(() => [
  {
    name: 'Leads',
    data: [props.leads.new, props.leads.won, props.leads.lost],
  },
]);
</script>

<template>
  <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
    <h3 class="text-sm font-semibold text-zinc-900">Estado de leads</h3>
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
