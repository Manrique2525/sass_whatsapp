/**
 * Tipos del módulo de flujos (FASE 11). Espejo de los Resources backend:
 * `ChatbotResource`, `FlowResource`, `FlowNodeResource`, `FlowConnectionResource`,
 * `TriggerResource` y `FlowExecutionResource`.
 */

export type FlowStatus = 'draft' | 'published' | 'inactive';

export type FlowNodeType =
    | 'message'
    | 'buttons'
    | 'question'
    | 'condition'
    | 'delay'
    | 'tag'
    | 'webhook'
    | 'ai'
    | 'human'
    | 'end';

export type FlowTriggerType = 'keyword' | 'new_message' | 'start' | 'tag' | 'schedule' | 'webhook';

export type FlowExecutionStatus = 'running' | 'waiting' | 'completed' | 'failed' | 'handed_off';

export interface Chatbot {
    id: string;
    name: string;
    description: string | null;
    flows_count?: number;
    flows?: Flow[];
    created_at: string;
    updated_at: string;
}

export interface FlowNode {
    id: string;
    type: FlowNodeType;
    type_label: string;
    name: string;
    position_x: number;
    position_y: number;
    config: Record<string, unknown> | null;
    is_start: boolean;
}

export interface FlowConnection {
    id: string;
    source_node_id: string;
    target_node_id: string;
    label: string | null;
}

export interface Flow {
    id: string;
    chatbot_id: string;
    name: string;
    description: string | null;
    status: FlowStatus;
    status_label: string;
    config: Record<string, unknown> | null;
    nodes?: FlowNode[];
    connections?: FlowConnection[];
    triggers?: Trigger[];
    chatbot?: Chatbot;
    triggers_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Trigger {
    id: string;
    flow_id: string;
    type: FlowTriggerType;
    type_label: string;
    keyword: string | null;
    config: Record<string, unknown> | null;
    priority: number;
    active: boolean;
    created_at: string;
    updated_at: string;
}

export interface FlowExecution {
    id: string;
    flow_id: string;
    conversation_id: string;
    status: FlowExecutionStatus;
    status_label: string;
    current_node_id: string | null;
    variables: Record<string, unknown>;
    attempts: number;
    last_inbound_message_id: string | null;
    flow?: Flow;
    conversation?: Record<string, unknown>;
    created_at: string;
    updated_at: string;
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ChatbotFilters {
    search?: string;
    page?: number;
    perPage?: number;
}

export interface FlowFilters {
    status?: FlowStatus | '';
    page?: number;
    perPage?: number;
}

export interface ExecutionFilters {
    status?: FlowExecutionStatus | '';
    flow_id?: string;
    chatbot_id?: string;
    page?: number;
    perPage?: number;
}

/**
 * Tipos de variable del motor (FASE 13). Espejo de `VariableType` backend.
 */
export type VariableType = 'string' | 'integer' | 'decimal' | 'boolean' | 'date' | 'datetime' | 'array' | 'object' | 'null';

/**
 * Namespaces del catálogo de variables (FASE 13, UNIDAD 4). Espejo de
 * `VariableCatalogService`/`VariableResolver`.
 */
export type VariableNamespace = 'contact' | 'business' | 'conversation' | 'custom';

/**
 * Definición de una variable del catálogo (FASE 13, UNIDAD 3/4). Espejo de
 * `VariableDefinitionResource` backend: SOLO la definición derivada, nunca
 * valores runtime ni datos sensibles.
 */
export interface VariableDefinition {
    key: string;
    label: string;
    namespace: VariableNamespace;
    source: string;
    type: VariableType;
    default: unknown;
    writable: boolean;
}
