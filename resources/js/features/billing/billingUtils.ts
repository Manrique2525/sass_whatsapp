import type { UsageCategorySummary } from './billingTypes';

export const CATEGORY_LABELS: Record<string, string> = {
  messages: 'Mensajes',
  ai_tokens: 'Tokens de IA',
  contacts: 'Contactos',
  flow_executions: 'Ejecuciones de flujo',
  users: 'Usuarios',
  knowledge_documents: 'Documentos de KB',
};

export function categoryLabel(key: string): string {
  return CATEGORY_LABELS[key] ?? key;
}

export function statusLabel(status: string): string {
  switch (status) {
    case 'active':
      return 'Activo';
    case 'cancelled':
      return 'Cancelado';
    default:
      return status;
  }
}

export function statusColor(status: string): string {
  switch (status) {
    case 'active':
      return 'bg-emerald-50 text-emerald-700';
    case 'cancelled':
      return 'bg-zinc-100 text-zinc-500';
    default:
      return 'bg-zinc-100 text-zinc-500';
  }
}

export function formatCurrency(amount: number | null): string {
  if (amount === null) {
    return '—';
  }

  return new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
  }).format(amount);
}

export function formatUsageValue(value: number): string {
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toFixed(1)}M`;
  }

  if (value >= 1_000) {
    return `${(value / 1_000).toFixed(1)}K`;
  }

  return String(value);
}

export function usagePercent(used: number, limit: number | null): number | null {
  if (limit === null || limit <= 0) {
    return null;
  }

  const pct = Math.round((used / limit) * 100);

  if (!Number.isFinite(pct) || Number.isNaN(pct)) {
    return 0;
  }

  return Math.min(pct, 100);
}

export function isUnlimited(limit: number | null): boolean {
  return limit === null;
}

export function formatDate(isoDate: string): string {
  const d = new Date(isoDate);

  return d.toLocaleDateString('es-MX', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  });
}

export function formatDateTime(isoDate: string): string {
  const d = new Date(isoDate);

  return d.toLocaleDateString('es-MX', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function extractErrorMessage(err: unknown, fallback: string): string {
  if (
    err !== null &&
    typeof err === 'object' &&
    'response' in err &&
    err.response !== null &&
    typeof err.response === 'object' &&
    'data' in err.response &&
    err.response.data !== null &&
    typeof err.response.data === 'object' &&
    'message' in err.response.data &&
    typeof err.response.data.message === 'string'
  ) {
    return err.response.data.message;
  }

  return fallback;
}

export interface UsageCategoryItem extends UsageCategorySummary {
  key: string;
  label: string;
}

export function buildUsageSummary(categories: Record<string, UsageCategorySummary>): UsageCategoryItem[] {
  return Object.entries(categories).map(([key, value]) => ({
    key,
    label: categoryLabel(key),
    used: value.used,
    limit: value.limit,
    remaining: value.remaining,
  }));
}
