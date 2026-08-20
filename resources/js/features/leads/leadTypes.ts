export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'won' | 'lost';

export type LeadSource = 'manual' | 'whatsapp' | 'web' | 'referral' | 'other';

export interface Lead {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  status: LeadStatus;
  source: LeadSource | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface LeadMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface LeadListResponse {
  leads: Lead[];
  meta: LeadMeta;
}

export interface LeadFilters {
  search: string;
  status: string;
  source: string;
  page: number;
  per_page?: number;
}

export interface LeadPayload {
  name: string;
  phone?: string | null;
  email?: string | null;
  status?: LeadStatus;
  source?: LeadSource | null;
  notes?: string | null;
}
