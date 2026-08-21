export type PresetKey = '7d' | '30d' | '90d' | 'custom';

export interface DateRange {
  from: string;
  to: string;
}

export interface MessagesStats {
  total: number;
  inbound: number;
  outbound: number;
  delivered: number;
  read: number;
  failed: number;
}

export interface ConversationsStats {
  total: number;
  open: number;
  resolved: number;
  archived: number;
  handoff_requested: number;
  bot_paused: number;
  unique_contacts: number;
  avg_response_time_seconds: number | null;
}

export interface FlowsStats {
  total: number;
  completed: number;
  failed: number;
}

export interface LeadsStats {
  total: number;
  new: number;
  won: number;
  lost: number;
}

export interface AiStats {
  total_tokens: number;
}

export interface DailyRow {
  date: string;
  messages_total: number;
  messages_inbound: number;
  messages_outbound: number;
  conversations_total: number;
  leads_total: number;
  flow_executions_total: number;
  ai_tokens: number;
}

export interface AnalyticsOverviewData {
  period: DateRange;
  messages: MessagesStats;
  conversations: ConversationsStats;
  flows: FlowsStats;
  leads: LeadsStats;
  ai: AiStats;
  daily: DailyRow[];
}
