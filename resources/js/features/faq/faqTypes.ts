export interface Faq {
  id: string;
  question: string;
  answer: string;
  status: 'active' | 'inactive';
  priority: number;
  created_at: string;
  updated_at: string;
}

export interface FaqMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface FaqListResponse {
  data: Faq[];
  meta: FaqMeta;
}

export interface FaqFilters {
  search: string;
  status: string;
  page: number;
  per_page?: number;
}

export interface FaqPayload {
  question: string;
  answer: string;
  priority: number;
  status: 'active' | 'inactive';
}
