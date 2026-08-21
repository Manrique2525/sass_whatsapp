import { describe, expect, it } from 'vitest';
import {
  safeRate,
  formatDuration,
  formatNumber,
  todayISO,
  daysAgoISO,
  getPresetRange,
  isValidRange,
  maxRangeDays,
  dateLabel,
  presetLabel,
  extractErrorMessage,
} from './analyticsUtils';

describe('safeRate', () => {
  it('AN-V01: normal ratio', () => {
    expect(safeRate(3, 4)).toBe(75);
  });

  it('AN-V02: zero denominator returns 0', () => {
    expect(safeRate(5, 0)).toBe(0);
  });

  it('AN-V02b: negative denominator returns 0', () => {
    expect(safeRate(5, -1)).toBe(0);
  });

  it('AN-V03: no NaN in output', () => {
    const result = safeRate(0, 0);
    expect(result).toBe(0);
    expect(Number.isNaN(result)).toBe(false);
  });

  it('AN-V03b: no Infinity in output', () => {
    const result = safeRate(1, 0);
    expect(Number.isFinite(result)).toBe(true);
  });

  it('rounds to one decimal', () => {
    expect(safeRate(1, 3)).toBe(33.3);
  });
});

describe('formatDuration', () => {
  it('AN-V04: seconds only', () => {
    expect(formatDuration(42)).toBe('42s');
  });

  it('AN-V04b: minutes and seconds', () => {
    expect(formatDuration(125)).toBe('2m 5s');
  });

  it('AN-V04c: exact minutes', () => {
    expect(formatDuration(120)).toBe('2m');
  });

  it('AN-V05: null returns dash', () => {
    expect(formatDuration(null)).toBe('—');
  });

  it('AN-V05b: zero seconds', () => {
    expect(formatDuration(0)).toBe('0s');
  });
});

describe('formatNumber', () => {
  it('plain number under 1K', () => {
    expect(formatNumber(42)).toBe('42');
  });

  it('thousands with K', () => {
    expect(formatNumber(1500)).toBe('1.5K');
  });

  it('millions with M', () => {
    expect(formatNumber(2500000)).toBe('2.5M');
  });

  it('exact 1000', () => {
    expect(formatNumber(1000)).toBe('1.0K');
  });
});

describe('todayISO', () => {
  it('returns YYYY-MM-DD format', () => {
    const result = todayISO();
    expect(result).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });
});

describe('daysAgoISO', () => {
  it('returns a date string', () => {
    const result = daysAgoISO(6);
    expect(result).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('returns date before today', () => {
    const result = daysAgoISO(1);
    expect(result < todayISO()).toBe(true);
  });
});

describe('getPresetRange', () => {
  it('AN-V06: 7d range has 7 days span', () => {
    const range = getPresetRange('7d');
    const from = new Date(range.from);
    const to = new Date(range.to);
    const diff = Math.round((to.getTime() - from.getTime()) / (1000 * 60 * 60 * 24));
    expect(diff).toBe(6);
  });

  it('AN-V07: 30d range has 30 days span', () => {
    const range = getPresetRange('30d');
    const from = new Date(range.from);
    const to = new Date(range.to);
    const diff = Math.round((to.getTime() - from.getTime()) / (1000 * 60 * 60 * 24));
    expect(diff).toBe(29);
  });

  it('AN-V08: 90d range has 90 days span', () => {
    const range = getPresetRange('90d');
    const from = new Date(range.from);
    const to = new Date(range.to);
    const diff = Math.round((to.getTime() - from.getTime()) / (1000 * 60 * 60 * 24));
    expect(diff).toBe(89);
  });

  it('AN-V09: custom range defaults to 30d', () => {
    const range = getPresetRange('custom');
    const from = new Date(range.from);
    const to = new Date(range.to);
    const diff = Math.round((to.getTime() - from.getTime()) / (1000 * 60 * 60 * 24));
    expect(diff).toBe(29);
  });
});

describe('isValidRange', () => {
  it('AN-V10: from <= to is valid', () => {
    expect(isValidRange({ from: '2026-01-01', to: '2026-01-15' })).toBe(true);
  });

  it('AN-V10b: from === to is valid', () => {
    expect(isValidRange({ from: '2026-01-01', to: '2026-01-01' })).toBe(true);
  });

  it('AN-V10c: from > to is invalid', () => {
    expect(isValidRange({ from: '2026-01-15', to: '2026-01-01' })).toBe(false);
  });
});

describe('maxRangeDays', () => {
  it('AN-V11: range within 365 days is valid', () => {
    expect(maxRangeDays({ from: '2026-01-01', to: '2026-06-01' })).toBe(true);
  });

  it('AN-V11b: range exceeding 365 days is invalid', () => {
    expect(maxRangeDays({ from: '2025-01-01', to: '2026-08-21' })).toBe(false);
  });

  it('AN-V11c: exact 365 days is valid', () => {
    expect(maxRangeDays({ from: '2025-08-21', to: '2026-08-20' })).toBe(true);
  });
});

describe('dateLabel', () => {
  it('AN-V12: formats date to short label', () => {
    const label = dateLabel('2026-03-15');
    expect(label).toContain('mar');
  });

  it('AN-V12b: returns string', () => {
    expect(typeof dateLabel('2026-01-01')).toBe('string');
  });
});

describe('presetLabel', () => {
  it('returns correct labels', () => {
    expect(presetLabel('7d')).toBe('7 días');
    expect(presetLabel('30d')).toBe('30 días');
    expect(presetLabel('90d')).toBe('90 días');
    expect(presetLabel('custom')).toBe('Personalizado');
  });
});

describe('extractErrorMessage', () => {
  it('extracts message from Axios error', () => {
    const err = {
      response: { data: { message: 'Permiso denegado.' } },
    };
    expect(extractErrorMessage(err, 'fallback')).toBe('Permiso denegado.');
  });

  it('returns fallback for non-Axios error', () => {
    expect(extractErrorMessage(new Error('fail'), 'fallback')).toBe('fallback');
  });

  it('returns fallback for null', () => {
    expect(extractErrorMessage(null, 'fallback')).toBe('fallback');
  });
});
