import type { FaqFilters } from './faqTypes';

export function buildFaqQuery(filters: FaqFilters): Record<string, string | number> {
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

  return params;
}

export function statusLabel(status: string): string {
  return status === 'active' ? 'Activa' : 'Inactiva';
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

export function buildFaqPayload(data: { question: string; answer: string; priority: number; status: string }): {
  question: string;
  answer: string;
  priority: number;
  status: 'active' | 'inactive';
} {
  return {
    question: data.question.trim(),
    answer: data.answer.trim(),
    priority: Math.max(0, Math.min(100, data.priority)),
    status: data.status === 'inactive' ? 'inactive' : 'active',
  };
}
