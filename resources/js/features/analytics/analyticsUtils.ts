import type { DateRange, PresetKey } from './analyticsTypes';

export function safeRate(numerator: number, denominator: number): number {
  if (denominator <= 0) {
    return 0;
  }

  return Math.round((numerator / denominator) * 1000) / 10;
}

export function formatDuration(seconds: number | null): string {
  if (seconds === null) {
    return '—';
  }

  if (seconds < 60) {
    return `${seconds}s`;
  }

  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;

  return secs > 0 ? `${mins}m ${secs}s` : `${mins}m`;
}

export function formatNumber(value: number): string {
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toFixed(1)}M`;
  }

  if (value >= 1_000) {
    return `${(value / 1_000).toFixed(1)}K`;
  }

  return String(value);
}

function formatDateISO(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');

  return `${y}-${m}-${d}`;
}

export function todayISO(): string {
  return formatDateISO(new Date());
}

export function daysAgoISO(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);

  return formatDateISO(d);
}

export function getPresetRange(preset: PresetKey): DateRange {
  switch (preset) {
    case '7d':
      return { from: daysAgoISO(6), to: todayISO() };
    case '30d':
      return { from: daysAgoISO(29), to: todayISO() };
    case '90d':
      return { from: daysAgoISO(89), to: todayISO() };
    default:
      return { from: daysAgoISO(29), to: todayISO() };
  }
}

export function isValidRange(range: DateRange): boolean {
  return range.from <= range.to;
}

export function maxRangeDays(range: DateRange): boolean {
  const from = new Date(range.from);
  const to = new Date(range.to);
  const diffMs = to.getTime() - from.getTime();
  const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24));

  return diffDays <= 365;
}

export function dateLabel(isoDate: string): string {
  const d = new Date(isoDate + 'T00:00:00');

  return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
}

export function presetLabel(preset: PresetKey): string {
  const labels: Record<PresetKey, string> = {
    '7d': '7 días',
    '30d': '30 días',
    '90d': '90 días',
    'custom': 'Personalizado',
  };

  return labels[preset];
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
