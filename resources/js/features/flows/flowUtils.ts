import type {
    ChatbotFilters,
    ExecutionFilters,
    FlowFilters,
    FlowNode,
    FlowNodeType,
    FlowStatus,
    FlowTriggerType,
} from './flowTypes';

/**
 * Etiquetas de estado de flujo (espejo de `FlowStatus::label()`).
 */
export function flowStatusLabel(status: FlowStatus): string {
    const labels: Record<FlowStatus, string> = {
        draft: 'Borrador',
        published: 'Publicado',
        inactive: 'Inactivo',
    };

    return labels[status] ?? status;
}

/**
 * Etiquetas de tipo de nodo (espejo de `FlowNodeType::label()`).
 */
export function nodeTypeLabel(type: FlowNodeType): string {
    const labels: Record<FlowNodeType, string> = {
        message: 'Mensaje',
        buttons: 'Botones',
        question: 'Pregunta',
        condition: 'Condición',
        delay: 'Espera',
        tag: 'Etiqueta',
        webhook: 'Webhook',
        ai: 'IA',
        human: 'Transferir a humano',
        end: 'Fin',
    };

    return labels[type] ?? type;
}

/**
 * Etiquetas de tipo de trigger (espejo de `FlowTriggerType::label()`).
 */
export function triggerTypeLabel(type: FlowTriggerType): string {
    const labels: Record<FlowTriggerType, string> = {
        keyword: 'Palabra clave',
        new_message: 'Nuevo mensaje',
        start: 'Primer mensaje',
        tag: 'Etiqueta',
        schedule: 'Programado',
        webhook: 'Webhook externo',
    };

    return labels[type] ?? type;
}

/**
 * Construye una descripción humana del `config` de un nodo para la vista
 * read-only. Nunca expone secretos (el webhook solo muestra URL + método).
 */
export function nodeConfigSummary(type: FlowNodeType, config: Record<string, unknown> | null | undefined): string {
    if (config === null || config === undefined) {
        return '';
    }

    switch (type) {
        case 'message':
            return typeof config.text === 'string' ? config.text : '';
        case 'buttons': {
            const text = typeof config.text === 'string' ? config.text : '';
            const buttons = Array.isArray(config.buttons)
                ? config.buttons
                      .filter((b): b is Record<string, unknown> => typeof b === 'object' && b !== null)
                      .map((b) => String(b.title ?? b.id ?? ''))
                      .filter((title) => title !== '')
                : [];

            return [text, buttons.length > 0 ? `Opciones: ${buttons.join(' · ')}` : ''].filter(Boolean).join(' — ');
        }
        case 'question':
            return [typeof config.prompt === 'string' ? config.prompt : '', typeof config.field === 'string' && config.field !== '' ? `campo "${config.field}"` : '']
                .filter(Boolean)
                .join(' — ');
        case 'condition': {
            const rules = Array.isArray(config.rules) ? config.rules : [];
            const summaries = rules
                .filter((r): r is Record<string, unknown> => typeof r === 'object' && r !== null)
                .map((r) => [String(r.field ?? ''), String(r.operator ?? ''), String(r.value ?? '')].filter(Boolean).join(' '));

            return summaries.length > 0 ? summaries.join(' Y ') : '';
        }
        case 'delay':
            return `Espera ${String(config.seconds ?? '')} s`;
        case 'tag': {
            const tags = Array.isArray(config.tags) ? config.tags.map(String) : [];

            return tags.length > 0 ? tags.join(', ') : '';
        }
        case 'webhook':
            return [String(config.method ?? 'POST'), String(config.url ?? '')].filter(Boolean).join(' ');
        case 'ai': {
            const p = typeof config.prompt === 'string' ? config.prompt : '';
            const v = typeof config.output_variable === 'string' ? config.output_variable : '';
            const preview = p.length > 40 ? p.slice(0, 40) + '…' : p;
            return [preview, v !== '' ? `→ ${v}` : ''].filter(Boolean).join(' ');
        }
        case 'human':
            return '';
        case 'end':
            return '';
        default:
            return '';
    }
}

/**
 * Construye los query params del listado de chatbots. Omite filtros vacíos.
 */
export function buildChatbotQuery(filters: ChatbotFilters): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.search !== undefined && filters.search.trim() !== '') {
        params.search = filters.search.trim();
    }

    if (filters.page !== undefined && filters.page > 1) {
        params.page = String(filters.page);
    }

    if (filters.perPage !== undefined && filters.perPage > 0) {
        params.per_page = String(filters.perPage);
    }

    return params;
}

/**
 * Construye los query params del listado de flujos. Omite filtros vacíos.
 */
export function buildFlowQuery(filters: FlowFilters): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.status !== undefined && filters.status !== '') {
        params.status = filters.status;
    }

    if (filters.page !== undefined && filters.page > 1) {
        params.page = String(filters.page);
    }

    if (filters.perPage !== undefined && filters.perPage > 0) {
        params.per_page = String(filters.perPage);
    }

    return params;
}

/**
 * Construye los query params del listado de ejecuciones. Omite filtros vacíos.
 */
export function buildExecutionQuery(filters: ExecutionFilters): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.status !== undefined && filters.status !== '') {
        params.status = filters.status;
    }

    if (filters.flow_id !== undefined && filters.flow_id.trim() !== '') {
        params.flow_id = filters.flow_id.trim();
    }

    if (filters.chatbot_id !== undefined && filters.chatbot_id.trim() !== '') {
        params.chatbot_id = filters.chatbot_id.trim();
    }

    if (filters.page !== undefined && filters.page > 1) {
        params.page = String(filters.page);
    }

    if (filters.perPage !== undefined && filters.perPage > 0) {
        params.per_page = String(filters.perPage);
    }

    return params;
}

/**
 * Extrae `message` del body de error de la API o devuelve el fallback.
 */
export function extractErrorMessage(err: unknown, fallback: string): string {
    if (
        typeof err === 'object' &&
        err !== null &&
        'response' in err &&
        typeof err.response === 'object' &&
        err.response !== null &&
        'data' in err.response &&
        typeof err.response.data === 'object' &&
        err.response.data !== null &&
        'message' in err.response.data &&
        typeof err.response.data.message === 'string'
    ) {
        return err.response.data.message;
    }

    return fallback;
}

/**
 * Resuelve el nodo de inicio de un flujo (si existe) para mostrarlo destacado.
 */
export function findStartNode(nodes: FlowNode[] | undefined): FlowNode | null {
    if (!Array.isArray(nodes)) {
        return null;
    }

    return nodes.find((node) => node.is_start) ?? null;
}

/**
 * Devuelve `true` para todos los tipos de nodo soportados. Usado para
 * filtrar y validar el catálogo de nodos disponibles.
 */
export function isImplementedNodeType(_type: FlowNodeType): boolean {
    return true;
}
