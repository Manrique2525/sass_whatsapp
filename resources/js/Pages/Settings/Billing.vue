<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import type {
  Plan,
  Subscription,
  UsageSummary,
  UsageRecord,
  UsageHistoryMeta,
} from '@/features/billing/billingTypes';
import {
  fetchPlans,
  fetchCurrentSubscription,
  fetchUsageSummary,
  fetchUsageHistory,
  assignPlan,
  changePlan,
  cancelSubscription,
  createCheckoutSession,
  createPortalSession,
} from '@/features/billing/billingApi';
import {
  statusLabel,
  statusColor,
  formatCurrency,
  formatUsageValue,
  usagePercent,
  isUnlimited,
  formatDate,
  formatDateTime,
  extractErrorMessage,
  categoryLabel,
} from '@/features/billing/billingUtils';

const page = usePage();
const user = page.props.auth.user;
const currentTenantId = computed(() => page.props.auth.current_tenant_id as string | null);
const permissions = computed(() => page.props.auth.permissions as string[]);

const can = (perm: string): boolean => permissions.value.includes(perm);
const canView = computed(() => can('billing.view'));
const canManage = computed(() => can('billing.manage'));

const loading = ref(true);
const error = ref<string | null>(null);

const subscription = ref<Subscription | null>(null);
const plans = ref<Plan[]>([]);
const usage = ref<UsageSummary | null>(null);
const historyRecords = ref<UsageRecord[]>([]);
const historyMeta = ref<UsageHistoryMeta>({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const historyPage = ref(1);
const historyLoading = ref(false);

const actionLoading = ref(false);
const actionError = ref<string | null>(null);
const actionSuccess = ref<string | null>(null);

const showPlanDialog = ref(false);
const selectedPlan = ref<Plan | null>(null);
const selectedInterval = ref<string>('monthly');
const planAction = ref<'assign' | 'change'>('assign');

const showCancelDialog = ref(false);

const loadAll = async (): Promise<void> => {
  if (!currentTenantId.value) {
    return;
  }

  loading.value = true;
  error.value = null;

  try {
    const [sub, planList, usageSummary] = await Promise.all([
      fetchCurrentSubscription(currentTenantId.value),
      fetchPlans(currentTenantId.value),
      fetchUsageSummary(currentTenantId.value).catch(() => null),
    ]);

    subscription.value = sub;
    plans.value = planList;
    usage.value = usageSummary;

    await loadHistory();
  } catch (err) {
    error.value = extractErrorMessage(err, 'No pudimos cargar la información de billing.');
  } finally {
    loading.value = false;
  }
};

const loadHistory = async (page_num?: number): Promise<void> => {
  if (!currentTenantId.value) {
    return;
  }

  const targetPage = page_num ?? historyPage.value;
  historyLoading.value = true;

  try {
    const result = await fetchUsageHistory(currentTenantId.value, {
      page: targetPage,
      per_page: 10,
    });
    historyRecords.value = result.records;
    historyMeta.value = result.meta;
    historyPage.value = targetPage;
  } catch {
    historyRecords.value = [];
  } finally {
    historyLoading.value = false;
  }
};

const goToHistoryPage = (target: number): void => {
  if (target < 1 || target > historyMeta.value.last_page) {
    return;
  }
  loadHistory(target);
};

const hasActiveSubscription = computed(() => subscription.value !== null && subscription.value.status !== 'cancelled');

const isPaidPlan = (plan: Plan): boolean => {
  return plan.price_monthly !== null && plan.price_monthly !== 0;
};

const openAssignPlan = (plan: Plan): void => {
  selectedPlan.value = plan;
  selectedInterval.value = 'monthly';
  planAction.value = hasActiveSubscription.value ? 'change' : 'assign';
  actionError.value = null;
  showPlanDialog.value = true;
};

const openPortal = async (): Promise<void> => {
  if (!currentTenantId.value) {
    return;
  }

  actionLoading.value = true;
  actionError.value = null;

  try {
    const portalUrl = await createPortalSession(currentTenantId.value);
    window.location.href = portalUrl;
  } catch (err) {
    actionError.value = extractErrorMessage(err, 'No se pudo abrir el portal de facturación.');
    actionLoading.value = false;
  }
};

const confirmPlanAction = async (): Promise<void> => {
  if (!currentTenantId.value || !selectedPlan.value) {
    return;
  }

  actionLoading.value = true;
  actionError.value = null;
  actionSuccess.value = null;

  try {
    if (isPaidPlan(selectedPlan.value)) {
      const checkoutUrl = await createCheckoutSession(
        currentTenantId.value,
        selectedPlan.value.id,
        selectedInterval.value,
      );
      showPlanDialog.value = false;
      window.location.href = checkoutUrl;
      return;
    }

    if (planAction.value === 'assign') {
      subscription.value = await assignPlan(currentTenantId.value, selectedPlan.value.id);
      actionSuccess.value = 'Plan asignado correctamente.';
    } else {
      subscription.value = await changePlan(currentTenantId.value, selectedPlan.value.id);
      actionSuccess.value = 'Plan actualizado correctamente.';
    }

    showPlanDialog.value = false;
    selectedPlan.value = null;

    const usageSummary = await fetchUsageSummary(currentTenantId.value).catch(() => null);
    usage.value = usageSummary;
  } catch (err) {
    actionError.value = extractErrorMessage(err, 'No se pudo completar la acción.');
  } finally {
    actionLoading.value = false;
  }
};

const openCancelDialog = (): void => {
  actionError.value = null;
  showCancelDialog.value = true;
};

const confirmCancel = async (): Promise<void> => {
  if (!currentTenantId.value) {
    return;
  }

  actionLoading.value = true;
  actionError.value = null;
  actionSuccess.value = null;

  try {
    await cancelSubscription(currentTenantId.value);
    subscription.value = null;
    usage.value = null;
    actionSuccess.value = 'Suscripción cancelada.';
    showCancelDialog.value = false;
  } catch (err) {
    actionError.value = extractErrorMessage(err, 'No se pudo cancelar la suscripción.');
  } finally {
    actionLoading.value = false;
  }
};

watch(currentTenantId, () => {
  subscription.value = null;
  plans.value = [];
  usage.value = null;
  historyRecords.value = [];
  historyPage.value = 1;
  actionSuccess.value = null;
  actionError.value = null;
  loadAll();
});

onMounted(() => {
  const urlParams = new URLSearchParams(window.location.search);
  const checkoutStatus = urlParams.get('checkout');

  if (checkoutStatus === 'success') {
    actionSuccess.value = 'El pago fue enviado para confirmación. Estamos actualizando el estado de tu suscripción.';
    window.history.replaceState({}, '', window.location.pathname);
  } else if (checkoutStatus === 'cancelled') {
    actionSuccess.value = 'El proceso de pago fue cancelado.';
    window.history.replaceState({}, '', window.location.pathname);
  }

  loadAll();
});
</script>

<template>
  <AppLayout :user="user">
    <div class="space-y-6">
      <div class="rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 class="text-xl font-semibold text-zinc-900">Billing</h2>
            <p class="mt-1 text-sm text-zinc-600">
              Gestiona tu plan, revisa el uso del periodo actual y consulta el historial de consumo.
            </p>
          </div>
          <button
            type="button"
            :disabled="loading"
            class="self-start rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50 disabled:opacity-50"
            @click="loadAll"
          >
            {{ loading ? 'Cargando...' : 'Actualizar' }}
          </button>
        </div>
      </div>

      <div v-if="actionSuccess" class="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ actionSuccess }}
      </div>

      <div v-if="actionError && !showPlanDialog && !showCancelDialog" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ actionError }}
      </div>

      <div v-if="!canView" class="rounded-xl border border-zinc-200 bg-white p-8 text-sm text-zinc-500 shadow-sm">
        No tienes permiso para ver billing.
      </div>

      <template v-else>
        <div v-if="error" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
          {{ error }}
          <button
            type="button"
            class="ml-2 underline hover:text-red-900"
            @click="loadAll"
          >
            Reintentar
          </button>
        </div>

        <div v-if="loading" class="space-y-6">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div v-for="i in 2" :key="i" class="animate-pulse rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
              <div class="h-4 w-24 rounded bg-zinc-200"></div>
              <div class="mt-3 h-8 w-32 rounded bg-zinc-200"></div>
              <div class="mt-2 h-4 w-40 rounded bg-zinc-100"></div>
            </div>
          </div>
          <div class="animate-pulse rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="h-4 w-32 rounded bg-zinc-200"></div>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
              <div v-for="i in 6" :key="i" class="h-20 rounded bg-zinc-100"></div>
            </div>
          </div>
        </div>

        <template v-else>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
              <h3 class="text-sm font-medium text-zinc-500">Plan actual</h3>
              <template v-if="subscription && hasActiveSubscription">
                <p class="mt-2 text-2xl font-semibold text-zinc-900">{{ subscription.plan.name }}</p>
                <div class="mt-2 flex items-center gap-2">
                  <span
                    class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="statusColor(subscription.status)"
                  >
                    {{ statusLabel(subscription.status) }}
                  </span>
                  <span class="text-xs text-zinc-400">{{ subscription.plan.slug }}</span>
                </div>
                <p v-if="subscription.cancel_at_period_end" class="mt-2 text-xs text-amber-600">
                  Tu suscripción seguirá activa hasta el final del período actual.
                </p>
                <p v-if="subscription.current_period_start && subscription.current_period_end" class="mt-2 text-xs text-zinc-400">
                  Periodo: {{ formatDate(subscription.current_period_start) }} — {{ formatDate(subscription.current_period_end) }}
                </p>
                <p v-if="subscription.plan.price_monthly !== null" class="mt-2 text-sm text-zinc-600">
                  {{ formatCurrency(subscription.plan.price_monthly) }}/mes
                </p>
              </template>
              <template v-else>
                <p class="mt-3 text-sm text-zinc-500">Sin suscripción activa.</p>
                <p class="mt-1 text-xs text-zinc-400">Selecciona un plan para comenzar.</p>
              </template>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
              <h3 class="text-sm font-medium text-zinc-500">Resumen de uso</h3>
              <template v-if="usage">
                <p class="mt-2 text-xs text-zinc-400">
                  Periodo: {{ formatDate(usage.period_start) }} — {{ formatDate(usage.period_end) }}
                </p>
                <div class="mt-3 space-y-2">
                  <div
                    v-for="(cat, key) in usage.categories"
                    :key="key"
                    class="flex items-center justify-between text-sm"
                  >
                    <span class="text-zinc-700">{{ categoryLabel(String(key)) }}</span>
                    <span class="font-medium text-zinc-900">
                      {{ formatUsageValue(cat.used) }}
                      <template v-if="isUnlimited(cat.limit)">
                        <span class="text-xs text-zinc-400">/ ∞</span>
                      </template>
                      <template v-else-if="cat.limit !== null">
                        <span class="text-xs text-zinc-400">/ {{ formatUsageValue(cat.limit) }}</span>
                      </template>
                    </span>
                  </div>
                </div>
              </template>
              <p v-else class="mt-3 text-sm text-zinc-500">Sin datos de uso disponibles.</p>
            </div>
          </div>

          <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-medium text-zinc-500">Uso detallado por categoría</h3>
            </div>
            <template v-if="usage && Object.keys(usage.categories).length > 0">
              <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                  v-for="(cat, key) in usage.categories"
                  :key="key"
                  class="rounded-lg border border-zinc-100 bg-zinc-50 p-4"
                >
                  <p class="text-xs font-medium text-zinc-500">{{ categoryLabel(String(key)) }}</p>
                  <p class="mt-1 text-xl font-semibold text-zinc-900">{{ formatUsageValue(cat.used) }}</p>
                  <template v-if="isUnlimited(cat.limit)">
                    <p class="mt-1 text-xs text-zinc-400">Ilimitado</p>
                  </template>
                  <template v-else-if="cat.limit !== null">
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200">
                      <div
                        class="h-full rounded-full transition-all"
                        :class="(usagePercent(cat.used, cat.limit) ?? 0) >= 90 ? 'bg-red-500' : 'bg-emerald-500'"
                        :style="{ width: `${usagePercent(cat.used, cat.limit) ?? 0}%` }"
                      ></div>
                    </div>
                    <p class="mt-1 text-xs text-zinc-400">
                      {{ cat.remaining !== null ? `${formatUsageValue(cat.remaining)} restantes` : '—' }}
                    </p>
                  </template>
                </div>
              </div>
            </template>
            <p v-else class="mt-4 text-sm text-zinc-500">Sin datos de uso para este periodo.</p>
          </div>

          <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-medium text-zinc-500">Planes disponibles</h3>
            <template v-if="plans.length > 0">
              <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                  v-for="plan in plans"
                  :key="plan.id"
                  class="relative rounded-lg border p-4 transition-colors"
                  :class="subscription?.plan.id === plan.id
                    ? 'border-emerald-500 bg-emerald-50'
                    : 'border-zinc-200 bg-white hover:border-zinc-300'"
                >
                  <div v-if="subscription?.plan.id === plan.id" class="absolute -top-2.5 left-4">
                    <span class="rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-medium text-white">
                      Actual
                    </span>
                  </div>
                  <p class="text-lg font-semibold text-zinc-900">{{ plan.name }}</p>
                  <p v-if="plan.description" class="mt-1 text-xs text-zinc-500">{{ plan.description }}</p>
                  <p v-if="plan.price_monthly !== null" class="mt-2 text-sm font-medium text-zinc-700">
                    {{ formatCurrency(plan.price_monthly) }}/mes
                  </p>
                  <p v-else class="mt-2 text-sm text-zinc-500">Gratuito</p>
                  <ul class="mt-3 space-y-1">
                    <li
                      v-for="(limit, key) in plan.limits"
                      :key="key"
                      class="flex items-center gap-1.5 text-xs text-zinc-600"
                    >
                      <span class="inline-block h-1 w-1 rounded-full bg-zinc-400"></span>
                      {{ categoryLabel(String(key)) }}:
                      {{ isUnlimited(limit as number | null) ? 'Ilimitado' : formatUsageValue(limit as number) }}
                    </li>
                  </ul>
                  <button
                    v-if="canManage && plan.is_active && subscription?.plan.id !== plan.id"
                    type="button"
                    :disabled="actionLoading"
                    class="mt-4 w-full rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 disabled:opacity-50"
                    @click="openAssignPlan(plan)"
                  >
                    {{ hasActiveSubscription ? 'Cambiar a este plan' : 'Seleccionar plan' }}
                  </button>
                  <span
                    v-else-if="subscription?.plan.id === plan.id"
                    class="mt-4 block text-center text-xs font-medium text-emerald-600"
                  >
                    Plan actual
                  </span>
                </div>
              </div>
            </template>
            <p v-else class="mt-4 text-sm text-zinc-500">No hay planes disponibles.</p>

            <div v-if="canManage && hasActiveSubscription" class="mt-4 border-t border-zinc-100 pt-4">
              <div class="flex items-center gap-4">
                <button
                  type="button"
                  :disabled="actionLoading"
                  class="text-sm text-zinc-600 underline hover:text-zinc-900 disabled:opacity-50"
                  @click="openPortal"
                >
                  Gestionar facturación
                </button>
                <button
                  type="button"
                  :disabled="actionLoading"
                  class="text-sm text-red-600 hover:underline disabled:opacity-50"
                  @click="openCancelDialog"
                >
                  Cancelar suscripción
                </button>
              </div>
            </div>
          </div>

          <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-medium text-zinc-500">Historial de uso</h3>
            <div v-if="historyLoading" class="mt-4 text-sm text-zinc-500">Cargando historial...</div>
            <div v-else-if="historyRecords.length === 0" class="mt-4 text-sm text-zinc-500">
              No hay registros de uso.
            </div>
            <template v-else>
              <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                  <thead>
                    <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500">
                      <th class="py-2 pr-4">Fecha</th>
                      <th class="py-2 pr-4">Categoría</th>
                      <th class="py-2 pr-4">Cantidad</th>
                      <th class="py-2">Descripción</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="record in historyRecords" :key="record.id" class="border-b border-zinc-100">
                      <td class="whitespace-nowrap py-3 pr-4 text-zinc-700">{{ formatDateTime(record.recorded_at) }}</td>
                      <td class="py-3 pr-4">
                        <span class="inline-block rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600">
                          {{ categoryLabel(record.category) }}
                        </span>
                      </td>
                      <td class="py-3 pr-4 font-medium text-zinc-900">{{ formatUsageValue(record.quantity) }}</td>
                      <td class="max-w-[16rem] truncate py-3 text-zinc-500">{{ record.description ?? '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="historyMeta.total > 0" class="mt-4 flex items-center justify-between text-sm">
                <p class="text-zinc-500">
                  Página {{ historyMeta.current_page }} de {{ historyMeta.last_page }} · {{ historyMeta.total }} registros
                </p>
                <div class="flex gap-2">
                  <button
                    type="button"
                    :disabled="historyMeta.current_page <= 1"
                    class="rounded-md border border-zinc-300 px-3 py-1.5 text-zinc-700 hover:bg-zinc-50 disabled:opacity-50"
                    @click="goToHistoryPage(historyMeta.current_page - 1)"
                  >
                    Anterior
                  </button>
                  <button
                    type="button"
                    :disabled="historyMeta.current_page >= historyMeta.last_page"
                    class="rounded-md border border-zinc-300 px-3 py-1.5 text-zinc-700 hover:bg-zinc-50 disabled:opacity-50"
                    @click="goToHistoryPage(historyMeta.current_page + 1)"
                  >
                    Siguiente
                  </button>
                </div>
              </div>
            </template>
          </div>
        </template>
      </template>
    </div>

    <div
      v-if="showPlanDialog && selectedPlan"
      class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
      @click.self="showPlanDialog = false"
      @keydown.escape="showPlanDialog = false"
    >
      <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg" role="dialog" aria-modal="true" :aria-label="planAction === 'assign' ? 'Asignar plan' : 'Cambiar plan'">
        <h3 class="text-lg font-semibold text-zinc-900">
          {{ planAction === 'assign' ? 'Asignar plan' : 'Cambiar de plan' }}
        </h3>
        <p class="mt-2 text-sm text-zinc-600">
          ¿Confirmas {{ planAction === 'assign' ? 'asignar' : 'cambiar a' }} el plan
          <span class="font-medium text-zinc-900">"{{ selectedPlan.name }}"</span>?
        </p>
        <p v-if="selectedPlan.price_monthly !== null && !isPaidPlan(selectedPlan)" class="mt-1 text-xs text-zinc-400">
          {{ formatCurrency(selectedPlan.price_monthly) }}/mes
        </p>

        <div v-if="selectedPlan && isPaidPlan(selectedPlan)" class="mt-4">
          <label class="text-xs font-medium text-zinc-500">Periodo de facturación</label>
          <div class="mt-2 flex gap-2">
            <button
              type="button"
              :class="[
                'flex-1 rounded-md border px-3 py-2 text-sm font-medium transition-colors',
                selectedInterval === 'monthly'
                  ? 'border-zinc-900 bg-zinc-900 text-white'
                  : 'border-zinc-300 bg-white text-zinc-700 hover:border-zinc-400',
              ]"
              @click="selectedInterval = 'monthly'"
            >
              Mensual
            </button>
            <button
              type="button"
              :class="[
                'flex-1 rounded-md border px-3 py-2 text-sm font-medium transition-colors',
                selectedInterval === 'yearly'
                  ? 'border-zinc-900 bg-zinc-900 text-white'
                  : 'border-zinc-300 bg-white text-zinc-700 hover:border-zinc-400',
              ]"
              @click="selectedInterval = 'yearly'"
            >
              Anual
            </button>
          </div>
          <p class="mt-2 text-xs text-zinc-400">
            <template v-if="selectedInterval === 'monthly'">
              {{ formatCurrency(selectedPlan.price_monthly) }}/mes
            </template>
            <template v-else-if="selectedPlan.price_yearly !== null">
              {{ formatCurrency(selectedPlan.price_yearly) }}/año
              <span v-if="selectedPlan.price_monthly && selectedPlan.price_monthly > 0" class="ml-1 text-emerald-600">
                (ahorra {{ Math.round((1 - selectedPlan.price_yearly / (selectedPlan.price_monthly * 12)) * 100) }}%)
              </span>
            </template>
          </p>
        </div>

        <div v-if="actionError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
          {{ actionError }}
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
            @click="showPlanDialog = false"
          >
            Cancelar
          </button>
          <button
            type="button"
            :disabled="actionLoading"
            class="rounded-md bg-zinc-900 px-5 py-2 text-sm font-semibold text-white hover:bg-zinc-700 disabled:opacity-50"
            @click="confirmPlanAction"
          >
            {{ actionLoading ? 'Procesando...' : (selectedPlan && isPaidPlan(selectedPlan) ? 'Ir a pagar' : 'Confirmar') }}
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="showCancelDialog"
      class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/40 p-4"
      @click.self="showCancelDialog = false"
      @keydown.escape="showCancelDialog = false"
    >
      <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-lg" role="dialog" aria-modal="true" aria-label="Cancelar suscripción">
        <h3 class="text-lg font-semibold text-zinc-900">Cancelar suscripción</h3>
        <p class="mt-2 text-sm text-zinc-600">
          ¿Confirmas cancelar tu suscripción actual? Perderás acceso a las funcionalidades del plan.
        </p>

        <div v-if="actionError" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
          {{ actionError }}
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-md border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:bg-zinc-50"
            @click="showCancelDialog = false"
          >
            No, mantener
          </button>
          <button
            type="button"
            :disabled="actionLoading"
            class="rounded-md bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
            @click="confirmCancel"
          >
            {{ actionLoading ? 'Cancelando...' : 'Sí, cancelar' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
