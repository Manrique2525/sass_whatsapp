export interface PlanLimits {
  messages?: number | null;
  ai_tokens?: number | null;
  contacts?: number | null;
  flow_executions?: number | null;
  users?: number | null;
  knowledge_documents?: number | null;
}

export interface Plan {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  is_active: boolean;
  price_monthly: number | null;
  price_yearly: number | null;
  limits: PlanLimits;
  features: Record<string, unknown>;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export interface SubscriptionPlan {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  is_active: boolean;
  price_monthly: number | null;
  price_yearly: number | null;
  limits: PlanLimits;
  features: Record<string, unknown>;
  sort_order: number;
  created_at: string;
  updated_at: string;
}

export type SubscriptionStatus = 'active' | 'cancelled' | 'pending' | 'past_due';

export interface Subscription {
  id: string;
  plan: SubscriptionPlan;
  status: SubscriptionStatus | string;
  quantity: number;
  cancel_at_period_end: boolean;
  current_period_start: string | null;
  current_period_end: string | null;
  created_at: string;
  updated_at: string;
}

export interface UsageCategorySummary {
  used: number;
  limit: number | null;
  remaining: number | null;
}

export interface UsageSummary {
  subscription_id: string;
  period_start: string;
  period_end: string;
  categories: Record<string, UsageCategorySummary>;
}

export interface UsageRecord {
  id: string;
  category: string;
  quantity: number;
  description: string | null;
  metadata: Record<string, unknown> | null;
  recorded_at: string;
  created_at: string;
}

export interface UsageHistoryMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface BillingActionState {
  loading: boolean;
  error: string | null;
  success: string | null;
}
