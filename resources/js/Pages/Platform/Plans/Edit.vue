<script setup lang="ts">
import PlanForm from './PlanForm.vue';
import { usePage } from '@inertiajs/vue3';
const plan = usePage().props.plan as Record<string, any>;
</script>
<template><PlanForm mode="edit" :plan="plan" /></template>
