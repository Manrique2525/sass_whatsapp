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
    historyMeta.value = { current_page: 1, last_page: 1, per_page: 10, total: 0 };
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

const isSafeRedirectUrl = (url: string): boolean => {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:';
  } catch {
    return false;
  }
};

const openPortal = async (): Promise<void> => {
  if (!currentTenantId.value) {
    return;
  }

  actionLoading.value = true;
  actionError.value = null;

  try {
    const portalUrl = await createPortalSession(currentTenantId.value);
    if (isSafeRedirectUrl(portalUrl)) {
      window.location.href = portalUrl;
    } else {
      actionError.value = 'URL de portal inválida.';
      actionLoading.value = false;
    }
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
      if (isSafeRedirectUrl(checkoutUrl)) {
        window.location.href = checkoutUrl;
      } else {
        actionError.value = 'URL de checkout inválida.';
        actionLoading.value = false;
      }
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
    showCancelDialog.value = false;

    // Refetch subscription to get accurate cancel_at_period_end state from backend.
    // Do NOT null locally — backend is source of truth (P1-04 hardening).
    const [sub, usageSummary] = await Promise.all([
      fetchCurrentSubscription(currentTenantId.value),
      fetchUsageSummary(currentTenantId.value).catch(() => null),
    ]);
    subscription.value = sub;
    usage.value = usageSummary;
    actionSuccess.value = 'Suscripción cancelada.';
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
  showPlanDialog.value = false;
  showCancelDialog.value = false;
  selectedPlan.value = null;
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
      <div class="app-card relative overflow-hidden p-6 sm:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="app-eyebrow">Planes y consumo</p>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-[#10261f]">Billing</h2>
            <p class="mt-1 text-sm leading-6 text-[#71877b]">
              Gestiona tu plan, revisa el uso del periodo actual y consulta el historial de consumo.
            </p>
          </div>
          <button
            type="button"
            :disabled="loading"
            class="app-button app-button--secondary self-start"
            @click="loadAll"
          >
            {{ loading ? 'Cargando...' : 'Actualizar' }}
          </button>
        </div>
      </div>

      <div v-if="actionSuccess" class="app-alert app-alert--success px-4">
        {{ actionSuccess }}
      </div>

      <div v-if="actionError && !showPlanDialog && !showCancelDialog" class="app-alert app-alert--error px-4">
        {{ actionError }}
      </div>

      <div v-if="!canView" class="app-card p-8 text-sm text-[#71877b]">
        No tienes permiso para ver billing.
      </div>

      <template v-else>
        <div v-if="error" class="app-alert app-alert--error px-4">
          {{ error }}
          <button
            type="button"
            class="ml-2 font-semibold underline hover:text-red-900"
            @click="loadAll"
          >
            Reintentar
          </button>
        </div>

        <div v-if="loading" class="space-y-6">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div v-for="i in 2" :key="i" class="animate-pulse app-card p-6">
              <div class="h-4 w-24 rounded bg-zinc-200"></div>
              <div class="mt-3 h-8 w-32 rounded bg-zinc-200"></div>
              <div class="mt-2 h-4 w-40 rounded bg-zinc-100"></div>
            </div>
          </div>
          <div class="animate-pulse app-card p-6">
            <div class="h-4 w-32 rounded bg-zinc-200"></div>
            <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
              <div v-for="i in 6" :key="i" class="h-20 rounded bg-zinc-100"></div>
            </div>
          </div>
        </div>

        <template v-else>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="app-card p-5 sm:p-6">
              <h3 class="app-eyebrow">Plan actual</h3>
              <template v-if="subscription && hasActiveSubscription">
                <p class="mt-2 text-2xl font-semibold tracking-tight text-[#10261f]">{{ subscription.plan.name }}</p>
                <div class="mt-2 flex items-center gap-2">
                  <span
                    class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="statusColor(subscription.status)"
                  >
                    {{ statusLabel(subscription.status) }}
                  </span>
                  <span class="text-xs text-[#9aaba1]">{{ subscription.plan.slug }}</span>
                </div>
                  <p v-if="subscription.cancel_at_period_end" class="mt-2 text-xs text-[#765d25]">
                  Tu suscripción seguirá activa hasta el final del período actual.
                </p>
                <p v-if="subscription.current_period_start && subscription.current_period_end" class="mt-2 text-xs text-[#9aaba1]">
                  Periodo: {{ formatDate(subscription.current_period_start) }} — {{ formatDate(subscription.current_period_end) }}
                </p>
                <p v-if="subscription.plan.price_monthly !== null" class="mt-2 text-sm text-[#64756d]">
                  {{ formatCurrency(subscription.plan.price_monthly) }}/mes
                </p>
              </template>
              <template v-else>
                <p class="mt-3 text-sm text-[#71877b]">Sin suscripción activa.</p>
                <p class="mt-1 text-xs text-[#9aaba1]">Selecciona un plan para comenzar.</p>
              </template>
            </div>

            <div class="app-card p-5 sm:p-6">
              <h3 class="app-eyebrow">Resumen de uso</h3>
              <template v-if="usage">
                  <p class="mt-2 text-xs text-[#9aaba1]">
                  Periodo: {{ formatDate(usage.period_start) }} — {{ formatDate(usage.period_end) }}
                </p>
                <div class="mt-3 space-y-2">
                  <div
                    v-for="(cat, key) in usage.categories"
                    :key="key"
                    class="flex items-center justify-between text-sm"
                  >
                    <span class="text-[#33483e]">{{ categoryLabel(String(key)) }}</span>
                    <span class="font-medium text-[#10261f]">
                      {{ formatUsageValue(cat.used) }}
                      <template v-if="isUnlimited(cat.limit)">
                        <span class="text-xs text-[#9aaba1]">/ ∞</span>
                      </template>
                      <template v-else-if="cat.limit !== null">
                        <span class="text-xs text-[#9aaba1]">/ {{ formatUsageValue(cat.limit) }}</span>
                      </template>
                    </span>
                  </div>
                </div>
              </template>
              <p v-else class="mt-3 text-sm text-[#71877b]">Sin datos de uso disponibles.</p>
            </div>
          </div>

          <div class="app-card p-5 sm:p-6">
            <div class="flex items-center justify-between">
              <h3 class="app-eyebrow">Uso detallado por categoría</h3>
            </div>
            <template v-if="usage && Object.keys(usage.categories).length > 0">
              <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                  v-for="(cat, key) in usage.categories"
                  :key="key"
                  class="rounded-2xl border border-[#dce8df] bg-[#eef3ed] p-4"
                >
                  <p class="text-xs font-medium text-[#71877b]">{{ categoryLabel(String(key)) }}</p>
                  <p class="mt-1 text-xl font-semibold text-[#10261f]">{{ formatUsageValue(cat.used) }}</p>
                  <template v-if="isUnlimited(cat.limit)">
                    <p class="mt-1 text-xs text-[#71877b]">Ilimitado</p>
                  </template>
                  <template v-else-if="cat.limit !== null">
                      <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-[#dce8df]">
                      <div
                        class="h-full rounded-full transition-all"
                        :class="(usagePercent(cat.used, cat.limit) ?? 0) >= 90 ? 'bg-red-500' : 'bg-emerald-500'"
                        :style="{ width: `${usagePercent(cat.used, cat.limit) ?? 0}%` }"
                      ></div>
                    </div>
                    <p class="mt-1 text-xs text-[#71877b]">
                      {{ cat.remaining !== null ? `${formatUsageValue(cat.remaining)} restantes` : '—' }}
                    </p>
                  </template>
                </div>
              </div>
            </template>
            <p v-else class="mt-4 text-sm text-[#71877b]">Sin datos de uso para este periodo.</p>
          </div>

          <div class="app-card p-5 sm:p-6">
            <h3 class="app-eyebrow">Planes disponibles</h3>
            <template v-if="plans.length > 0">
              <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                  v-for="plan in plans"
                  :key="plan.id"
                  class="relative rounded-2xl border p-4 transition-colors"
                  :class="subscription?.plan.id === plan.id
                    ? 'border-[#0b8f5a] bg-[#eef8ed]'
                    : 'border-[#dce8df] bg-white hover:border-[#91aa9a]'"
                >
                  <div v-if="subscription?.plan.id === plan.id" class="absolute -top-2.5 left-4">
                    <span class="rounded-full bg-[#0b8f5a] px-2.5 py-1 text-xs font-semibold text-white">
                      Actual
                    </span>
                  </div>
                  <p class="text-lg font-semibold text-[#10261f]">{{ plan.name }}</p>
                  <p v-if="plan.description" class="mt-1 text-xs text-[#71877b]">{{ plan.description }}</p>
                  <p v-if="plan.price_monthly !== null" class="mt-2 text-sm font-medium text-[#33483e]">
                    {{ formatCurrency(plan.price_monthly) }}/mes
                  </p>
                  <p v-else class="mt-2 text-sm text-[#71877b]">Gratuito</p>
                  <ul class="mt-3 space-y-1">
                    <li
                      v-for="(limit, key) in plan.limits"
                      :key="key"
                      class="flex items-center gap-1.5 text-xs text-[#64756d]"
                    >
                      <span class="inline-block h-1 w-1 rounded-full bg-[#91aa9a]"></span>
                      {{ categoryLabel(String(key)) }}:
                      {{ isUnlimited(limit as number | null) ? 'Ilimitado' : formatUsageValue(limit as number) }}
                    </li>
                  </ul>
                  <button
                    v-if="canManage && plan.is_active && subscription?.plan.id !== plan.id"
                    type="button"
                    :disabled="actionLoading"
                    class="app-button app-button--primary mt-4 w-full"
                    @click="openAssignPlan(plan)"
                  >
                    {{ hasActiveSubscription ? 'Cambiar a este plan' : 'Seleccionar plan' }}
                  </button>
                  <span
                    v-else-if="subscription?.plan.id === plan.id"
                    class="mt-4 block text-center text-xs font-semibold text-[#0b8f5a]"
                  >
                    Plan actual
                  </span>
                </div>
              </div>
            </template>
            <p v-else class="mt-4 text-sm text-[#71877b]">No hay planes disponibles.</p>

            <div v-if="canManage && hasActiveSubscription" class="mt-4 border-t border-[#dce8df] pt-4">
              <div class="flex items-center gap-4">
                <button
                  type="button"
                  :disabled="actionLoading"
                  class="text-sm font-semibold text-[#33483e] underline hover:text-[#0b8f5a] disabled:opacity-50"
                  @click="openPortal"
                >
                  Gestionar facturación
                </button>
                <button
                  type="button"
                  :disabled="actionLoading"
                  class="text-sm font-semibold text-[#b42318] hover:underline disabled:opacity-50"
                  @click="openCancelDialog"
                >
                  Cancelar suscripción
                </button>
              </div>
            </div>
          </div>

          <div class="app-card p-5 sm:p-6">
            <h3 class="app-eyebrow">Historial de uso</h3>
            <div v-if="historyLoading" class="mt-4 text-sm text-[#71877b]">Cargando historial...</div>
            <div v-else-if="historyRecords.length === 0" class="mt-4 text-sm text-[#71877b]">
              No hay registros de uso.
            </div>
            <template v-else>
              <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                  <thead>
                    <tr class="border-b border-[#dce8df] text-xs uppercase text-[#71877b]">
                      <th class="py-2 pr-4">Fecha</th>
                      <th class="py-2 pr-4">Categoría</th>
                      <th class="py-2 pr-4">Cantidad</th>
                      <th class="py-2">Descripción</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="record in historyRecords" :key="record.id" class="border-b border-[#edf2ec]">
                      <td class="whitespace-nowrap py-3 pr-4 text-[#33483e]">{{ formatDateTime(record.recorded_at) }}</td>
                      <td class="py-3 pr-4">
                        <span class="inline-block rounded-full bg-[#eef3ed] px-2.5 py-0.5 text-xs font-medium text-[#64756d]">
                          {{ categoryLabel(record.category) }}
                        </span>
                      </td>
                      <td class="py-3 pr-4 font-medium text-[#10261f]">{{ formatUsageValue(record.quantity) }}</td>
                      <td class="max-w-[16rem] truncate py-3 text-[#71877b]">{{ record.description ?? '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="historyMeta.total > 0" class="mt-4 flex items-center justify-between text-sm">
                <p class="text-[#71877b]">
                  Página {{ historyMeta.current_page }} de {{ historyMeta.last_page }} · {{ historyMeta.total }} registros
                </p>
                <div class="flex gap-2">
                  <button
                    type="button"
                    :disabled="historyMeta.current_page <= 1"
                    class="app-button app-button--secondary px-3 py-1.5 text-xs"
                    @click="goToHistoryPage(historyMeta.current_page - 1)"
                  >
                    Anterior
                  </button>
                  <button
                    type="button"
                    :disabled="historyMeta.current_page >= historyMeta.last_page"
                    class="app-button app-button--secondary px-3 py-1.5 text-xs"
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
        class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/40 p-4"
      @click.self="showPlanDialog = false"
      @keydown.escape="showPlanDialog = false"
    >
      <div class="app-card w-full max-w-md p-6" role="dialog" aria-modal="true" :aria-label="planAction === 'assign' ? 'Asignar plan' : 'Cambiar plan'">
        <h3 class="text-lg font-semibold text-[#10261f]">
          {{ planAction === 'assign' ? 'Asignar plan' : 'Cambiar de plan' }}
        </h3>
        <p class="mt-2 text-sm text-[#64756d]">
          ¿Confirmas {{ planAction === 'assign' ? 'asignar' : 'cambiar a' }} el plan
          <span class="font-medium text-[#10261f]">"{{ selectedPlan.name }}"</span>?
        </p>
        <p v-if="selectedPlan.price_monthly !== null && !isPaidPlan(selectedPlan)" class="mt-1 text-xs text-[#9aaba1]">
          {{ formatCurrency(selectedPlan.price_monthly) }}/mes
        </p>

        <div v-if="selectedPlan && isPaidPlan(selectedPlan)" class="mt-4">
          <label class="text-xs font-medium text-[#71877b]">Periodo de facturación</label>
          <div class="mt-2 flex gap-2">
            <button
              type="button"
              :class="[
                'app-button flex-1 border px-3 py-2 text-sm',
                selectedInterval === 'monthly'
                  ? 'border-[#10261f] bg-[#10261f] text-white'
                  : 'border-[#cbdacf] bg-white text-[#33483e] hover:border-[#91aa9a]',
              ]"
              @click="selectedInterval = 'monthly'"
            >
              Mensual
            </button>
            <button
              type="button"
              :class="[
                'app-button flex-1 border px-3 py-2 text-sm',
                selectedInterval === 'yearly'
                  ? 'border-[#10261f] bg-[#10261f] text-white'
                  : 'border-[#cbdacf] bg-white text-[#33483e] hover:border-[#91aa9a]',
              ]"
              @click="selectedInterval = 'yearly'"
            >
              Anual
            </button>
          </div>
          <p class="mt-2 text-xs text-[#9aaba1]">
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

        <div v-if="actionError" class="app-alert app-alert--error mt-3 px-3 py-2">
          {{ actionError }}
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <button
            type="button"
            class="app-button app-button--secondary"
            @click="showPlanDialog = false"
          >
            Cancelar
          </button>
          <button
            type="button"
            :disabled="actionLoading"
            class="app-button app-button--primary px-5"
            @click="confirmPlanAction"
          >
            {{ actionLoading ? 'Procesando...' : (selectedPlan && isPaidPlan(selectedPlan) ? 'Ir a pagar' : 'Confirmar') }}
          </button>
        </div>
      </div>
    </div>

      <div
        v-if="showCancelDialog"
        class="fixed inset-0 z-50 flex items-center justify-center bg-[#10261f]/40 p-4"
      @click.self="showCancelDialog = false"
      @keydown.escape="showCancelDialog = false"
    >
      <div class="app-card w-full max-w-sm p-6" role="dialog" aria-modal="true" aria-label="Cancelar suscripción">
        <h3 class="text-lg font-semibold text-[#10261f]">Cancelar suscripción</h3>
        <p class="mt-2 text-sm text-[#64756d]">
          ¿Confirmas cancelar tu suscripción actual? Perderás acceso a las funcionalidades del plan.
        </p>

        <div v-if="actionError" class="app-alert app-alert--error mt-3 px-3 py-2">
          {{ actionError }}
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <button
            type="button"
            class="app-button app-button--secondary"
            @click="showCancelDialog = false"
          >
            No, mantener
          </button>
          <button
            type="button"
            :disabled="actionLoading"
            class="app-button app-button--danger px-5"
            @click="confirmCancel"
          >
            {{ actionLoading ? 'Cancelando...' : 'Sí, cancelar' }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
