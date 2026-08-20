import { describe, expect, it } from 'vitest';
import {
  buildLeadQuery,
  statusLabel,
  sourceLabel,
  extractErrorMessage,
  buildLeadPayload,
  buildLeadEditPayload,
  allowedLeadTransitions,
  statusColor,
} from './leadUtils';

describe('statusLabel', () => {
  it('LEAD-V01: status labels', () => {
    expect(statusLabel('new')).toBe('Nuevo');
    expect(statusLabel('contacted')).toBe('Contactado');
    expect(statusLabel('qualified')).toBe('Calificado');
    expect(statusLabel('won')).toBe('Ganado');
    expect(statusLabel('lost')).toBe('Perdido');
  });

  it('LEAD-V01b: unknown status returns raw value', () => {
    expect(statusLabel('unknown')).toBe('unknown');
  });
});

describe('sourceLabel', () => {
  it('LEAD-V02: source labels', () => {
    expect(sourceLabel('manual')).toBe('Manual');
    expect(sourceLabel('whatsapp')).toBe('WhatsApp');
    expect(sourceLabel('web')).toBe('Web');
    expect(sourceLabel('referral')).toBe('Referido');
    expect(sourceLabel('other')).toBe('Otro');
  });

  it('LEAD-V02b: null source returns "Sin origen"', () => {
    expect(sourceLabel(null)).toBe('Sin origen');
  });
});

describe('buildLeadQuery', () => {
  it('LEAD-V03: search included when non-empty', () => {
    const params = buildLeadQuery({ search: 'juan', status: '', source: '', page: 1 });
    expect(params.search).toBe('juan');
  });

  it('LEAD-V03b: search omitted when empty', () => {
    const params = buildLeadQuery({ search: '', status: '', source: '', page: 1 });
    expect(params).not.toHaveProperty('search');
  });

  it('LEAD-V04: status filter included', () => {
    const params = buildLeadQuery({ search: '', status: 'new', source: '', page: 1 });
    expect(params.status).toBe('new');
  });

  it('LEAD-V04b: status omitted when empty', () => {
    const params = buildLeadQuery({ search: '', status: '', source: '', page: 1 });
    expect(params).not.toHaveProperty('status');
  });

  it('LEAD-V05: source filter included', () => {
    const params = buildLeadQuery({ search: '', status: '', source: 'web', page: 1 });
    expect(params.source).toBe('web');
  });

  it('LEAD-V05b: source omitted when empty', () => {
    const params = buildLeadQuery({ search: '', status: '', source: '', page: 1 });
    expect(params).not.toHaveProperty('source');
  });

  it('LEAD-V06: pagination included', () => {
    const params = buildLeadQuery({ search: '', status: '', source: '', page: 3 });
    expect(params.page).toBe(3);
  });

  it('LEAD-V06b: per_page included when set', () => {
    const params = buildLeadQuery({ search: '', status: '', source: '', page: 1, per_page: 50 });
    expect(params.per_page).toBe(50);
  });

  it('LEAD-V06c: per_page omitted when not set', () => {
    const params = buildLeadQuery({ search: '', status: '', source: '', page: 1 });
    expect(params).not.toHaveProperty('per_page');
  });
});

describe('buildLeadPayload', () => {
  it('LEAD-V07: whitelist only allowed fields', () => {
    const payload = buildLeadPayload({
      name: 'Juan',
      phone: '+529931234567',
      email: 'juan@test.com',
      source: 'web',
      notes: 'Notas',
    });

    expect(payload).toEqual({
      name: 'Juan',
      phone: '+529931234567',
      email: 'juan@test.com',
      source: 'web',
      notes: 'Notas',
    });
  });

  it('LEAD-V07b: no tenant_id in payload', () => {
    const payload = buildLeadPayload({
      name: 'Test',
      phone: '',
      email: '',
      source: '',
      notes: '',
    });

    expect(payload).not.toHaveProperty('tenant_id');
  });

  it('LEAD-V08: tenant_id excluded from payload', () => {
    const payload = buildLeadPayload({
      name: 'Test',
      phone: '',
      email: '',
      source: '',
      notes: '',
    });

    expect(Object.keys(payload)).not.toContain('tenant_id');
    expect(Object.keys(payload)).not.toContain('id');
    expect(Object.keys(payload)).not.toContain('created_at');
    expect(Object.keys(payload)).not.toContain('updated_at');
    expect(Object.keys(payload)).not.toContain('deleted_at');
  });

  it('trims name', () => {
    const payload = buildLeadPayload({
      name: '  Juan  ',
      phone: '',
      email: '',
      source: '',
      notes: '',
    });
    expect(payload.name).toBe('Juan');
  });

  it('omits empty optional fields', () => {
    const payload = buildLeadPayload({
      name: 'Test',
      phone: '',
      email: '',
      source: '',
      notes: '',
    });
    expect(payload).not.toHaveProperty('phone');
    expect(payload).not.toHaveProperty('email');
    expect(payload).not.toHaveProperty('source');
    expect(payload).not.toHaveProperty('notes');
  });
});

describe('buildLeadEditPayload', () => {
  it('includes status in edit payload', () => {
    const payload = buildLeadEditPayload({
      name: 'Test',
      phone: '',
      email: '',
      source: '',
      notes: '',
      status: 'contacted',
    });
    expect(payload.status).toBe('contacted');
  });
});

describe('allowedLeadTransitions', () => {
  it('LEAD-V09: new → contacted', () => {
    expect(allowedLeadTransitions('new')).toEqual(['contacted']);
  });

  it('LEAD-V10: contacted → qualified, won, lost', () => {
    expect(allowedLeadTransitions('contacted')).toEqual(['qualified', 'won', 'lost']);
  });

  it('LEAD-V11: qualified → won, lost', () => {
    expect(allowedLeadTransitions('qualified')).toEqual(['won', 'lost']);
  });

  it('LEAD-V12: lost → new (reopen)', () => {
    expect(allowedLeadTransitions('lost')).toEqual(['new']);
  });

  it('LEAD-V13: won → none (terminal)', () => {
    expect(allowedLeadTransitions('won')).toEqual([]);
  });
});

describe('statusColor', () => {
  it('returns correct classes for each status', () => {
    expect(statusColor('new')).toContain('zinc');
    expect(statusColor('contacted')).toContain('blue');
    expect(statusColor('qualified')).toContain('amber');
    expect(statusColor('won')).toContain('emerald');
    expect(statusColor('lost')).toContain('red');
  });
});

describe('extractErrorMessage', () => {
  it('extracts message from Axios error', () => {
    const err = {
      response: {
        data: { message: 'El lead ya existe.' },
      },
    };
    expect(extractErrorMessage(err, 'fallback')).toBe('El lead ya existe.');
  });

  it('returns fallback for non-Axios error', () => {
    expect(extractErrorMessage(new Error('fail'), 'fallback')).toBe('fallback');
  });

  it('returns fallback for null', () => {
    expect(extractErrorMessage(null, 'fallback')).toBe('fallback');
  });
});
