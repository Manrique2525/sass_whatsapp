import type { LeadFilters, LeadPayload, LeadStatus } from './leadTypes';

export function buildLeadQuery(filters: LeadFilters): Record<string, string | number> {
  const params: Record<string, string | number> = {
    page: filters.page,
  };

  if (filters.per_page) {
    params.per_page = filters.per_page;
  }

  if (filters.search.trim() !== '') {
    params.search = filters.search.trim();
  }

  if (filters.status !== '') {
    params.status = filters.status;
  }

  if (filters.source !== '') {
    params.source = filters.source;
  }

  return params;
}

export function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    new: 'Nuevo',
    contacted: 'Contactado',
    qualified: 'Calificado',
    won: 'Ganado',
    lost: 'Perdido',
  };

  return labels[status] ?? status;
}

export function sourceLabel(source: string | null): string {
  if (source === null) {
    return 'Sin origen';
  }

  const labels: Record<string, string> = {
    manual: 'Manual',
    whatsapp: 'WhatsApp',
    web: 'Web',
    referral: 'Referido',
    other: 'Otro',
  };

  return labels[source] ?? source;
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

export function buildLeadPayload(data: {
  name: string;
  phone: string;
  email: string;
  source: string;
  notes: string;
}): LeadPayload {
  const payload: LeadPayload = {
    name: data.name.trim(),
  };

  if (data.phone.trim() !== '') {
    payload.phone = data.phone.trim();
  }

  if (data.email.trim() !== '') {
    payload.email = data.email.trim();
  }

  if (data.source !== '') {
    payload.source = data.source as LeadPayload['source'];
  }

  if (data.notes.trim() !== '') {
    payload.notes = data.notes.trim();
  }

  return payload;
}

export function buildLeadEditPayload(data: {
  name: string;
  phone: string;
  email: string;
  source: string;
  notes: string;
  status: string;
}): LeadPayload {
  const payload: LeadPayload = buildLeadPayload(data);

  if (data.status !== '') {
    payload.status = data.status as LeadStatus;
  }

  return payload;
}

/**
 * Allowed status transitions matching backend LeadStatus.canTransitionTo().
 */
const ALLOWED_TRANSITIONS: Record<LeadStatus, LeadStatus[]> = {
  new: ['contacted'],
  contacted: ['qualified', 'won', 'lost'],
  qualified: ['won', 'lost'],
  won: [],
  lost: ['new'],
};

export function allowedLeadTransitions(currentStatus: LeadStatus): LeadStatus[] {
  return ALLOWED_TRANSITIONS[currentStatus] ?? [];
}

export function statusColor(status: LeadStatus): string {
  const colors: Record<LeadStatus, string> = {
    new: 'bg-zinc-100 text-zinc-700',
    contacted: 'bg-blue-50 text-blue-700',
    qualified: 'bg-amber-50 text-amber-700',
    won: 'bg-emerald-50 text-emerald-700',
    lost: 'bg-red-50 text-red-600',
  };

  return colors[status] ?? 'bg-zinc-100 text-zinc-500';
}
