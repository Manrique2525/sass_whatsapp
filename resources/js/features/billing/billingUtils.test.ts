import { describe, expect, it } from 'vitest';
import {
  categoryLabel,
  statusLabel,
  statusColor,
  formatCurrency,
  formatUsageValue,
  usagePercent,
  isUnlimited,
  formatDate,
  formatDateTime,
  extractErrorMessage,
  buildUsageSummary,
} from './billingUtils';

describe('categoryLabel', () => {
  it('BILL-FE-U4-17a: returns Spanish label for known category', () => {
    expect(categoryLabel('messages')).toBe('Mensajes');
    expect(categoryLabel('ai_tokens')).toBe('Tokens de IA');
    expect(categoryLabel('contacts')).toBe('Contactos');
    expect(categoryLabel('flow_executions')).toBe('Ejecuciones de flujo');
    expect(categoryLabel('users')).toBe('Usuarios');
    expect(categoryLabel('knowledge_documents')).toBe('Documentos de KB');
  });

  it('BILL-FE-U4-17b: returns raw key for unknown category', () => {
    expect(categoryLabel('unknown_cat')).toBe('unknown_cat');
  });
});

describe('statusLabel', () => {
  it('returns correct labels', () => {
    expect(statusLabel('active')).toBe('Activo');
    expect(statusLabel('cancelled')).toBe('Cancelado');
    expect(statusLabel('pending')).toBe('Procesando');
    expect(statusLabel('past_due')).toBe('Pago vencido');
    expect(statusLabel('unknown')).toBe('unknown');
  });
});

describe('statusColor', () => {
  it('returns correct color classes', () => {
    expect(statusColor('active')).toContain('emerald');
    expect(statusColor('cancelled')).toContain('zinc');
    expect(statusColor('pending')).toContain('amber');
    expect(statusColor('past_due')).toContain('red');
  });
});

describe('formatCurrency', () => {
  it('BILL-FE-U4-17c: formats zero as currency', () => {
    expect(formatCurrency(0)).toContain('0');
  });

  it('formats positive amount', () => {
    const result = formatCurrency(999);
    expect(result).toContain('999');
  });

  it('returns dash for null', () => {
    expect(formatCurrency(null)).toBe('—');
  });
});

describe('formatUsageValue', () => {
  it('BILL-FE-U4-19a: plain number under 1K', () => {
    expect(formatUsageValue(42)).toBe('42');
  });

  it('BILL-FE-U4-19b: thousands with K', () => {
    expect(formatUsageValue(1500)).toBe('1.5K');
  });

  it('BILL-FE-U4-19c: millions with M', () => {
    expect(formatUsageValue(2500000)).toBe('2.5M');
  });

  it('BILL-FE-U4-19d: zero', () => {
    expect(formatUsageValue(0)).toBe('0');
  });
});

describe('usagePercent', () => {
  it('calculates percentage correctly', () => {
    expect(usagePercent(50, 100)).toBe(50);
    expect(usagePercent(75, 100)).toBe(75);
    expect(usagePercent(100, 100)).toBe(100);
  });

  it('BILL-FE-U4-17d: returns null for unlimited (null limit)', () => {
    expect(usagePercent(50, null)).toBeNull();
  });

  it('BILL-FE-U4-19e: caps at 100', () => {
    expect(usagePercent(150, 100)).toBe(100);
  });

  it('BILL-FE-U4-19f: returns null for zero limit', () => {
    const result = usagePercent(0, 0);
    expect(result).toBeNull();
  });

  it('BILL-FE-U4-19g: returns null for zero limit with nonzero used', () => {
    const result = usagePercent(1, 0);
    expect(result).toBeNull();
  });
});

describe('isUnlimited', () => {
  it('returns true for null', () => {
    expect(isUnlimited(null)).toBe(true);
  });

  it('returns false for number', () => {
    expect(isUnlimited(100)).toBe(false);
    expect(isUnlimited(0)).toBe(false);
  });
});

describe('formatDate', () => {
  it('BILL-FE-U4-17e: formats ISO date', () => {
    const result = formatDate('2026-08-15T00:00:00Z');
    expect(result).toContain('2026');
    expect(typeof result).toBe('string');
  });
});

describe('formatDateTime', () => {
  it('BILL-FE-U4-17f: formats ISO datetime', () => {
    const result = formatDateTime('2026-08-15T14:30:00Z');
    expect(result).toContain('2026');
    expect(typeof result).toBe('string');
  });
});

describe('extractErrorMessage', () => {
  it('extracts message from Axios error', () => {
    const err = { response: { data: { message: 'Permiso denegado.' } } };
    expect(extractErrorMessage(err, 'fallback')).toBe('Permiso denegado.');
  });

  it('returns fallback for non-Axios error', () => {
    expect(extractErrorMessage(new Error('fail'), 'fallback')).toBe('fallback');
  });

  it('returns fallback for null', () => {
    expect(extractErrorMessage(null, 'fallback')).toBe('fallback');
  });
});

describe('buildUsageSummary', () => {
  it('BILL-FE-U4-17g: builds array from categories record', () => {
    const categories = {
      messages: { used: 50, limit: 100, remaining: 50 },
      ai_tokens: { used: 200, limit: null, remaining: null },
    };

    const result = buildUsageSummary(categories);

    expect(result).toHaveLength(2);
    expect(result[0].key).toBe('messages');
    expect(result[0].label).toBe('Mensajes');
    expect(result[0].used).toBe(50);
    expect(result[0].limit).toBe(100);
    expect(result[1].key).toBe('ai_tokens');
    expect(result[1].label).toBe('Tokens de IA');
    expect(result[1].limit).toBeNull();
  });

  it('returns empty array for empty categories', () => {
    expect(buildUsageSummary({})).toHaveLength(0);
  });
});
