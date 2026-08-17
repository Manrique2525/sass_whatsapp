import type { FlowNodeType } from './flowTypes';
import type { EditorValidationIssue, FlowEditorEdge, FlowEditorNode } from './flowEditorTypes';
import { isTerminalNodeType } from './flowAdapter';

/**
 * Validación UX del editor (FASE 12). El backend (`FlowValidator`) es la
 * autoridad final: aquí NO se duplica la lógica de negocio, solo se anticipan
 * errores de forma local (panel inferior) y se mapean los errores del backend
 * a issues accionables (con `nodeId` para enfocar/remarcar el nodo).
 */

export const CONDITION_OPERATORS: { value: string; label: string; needsValue: boolean }[] = [
    { value: 'equals', label: 'igual a', needsValue: true },
    { value: 'not_equals', label: 'distinto de', needsValue: true },
    { value: 'contains', label: 'contiene', needsValue: true },
    { value: 'not_contains', label: 'no contiene', needsValue: true },
    { value: 'greater_than', label: 'mayor que', needsValue: true },
    { value: 'less_than', label: 'menor que', needsValue: true },
    { value: 'greater_or_equal', label: 'mayor o igual que', needsValue: true },
    { value: 'less_or_equal', label: 'menor o igual que', needsValue: true },
    { value: 'exists', label: 'existe', needsValue: false },
    { value: 'not_exists', label: 'no existe', needsValue: false },
    { value: 'is_empty', label: 'está vacío', needsValue: false },
    { value: 'is_not_empty', label: 'no está vacío', needsValue: false },
];

export function conditionOperatorNeedsValue(operator: string): boolean {
    return CONDITION_OPERATORS.find((op) => op.value === operator)?.needsValue ?? true;
}

const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
const MAX_TEXT_LENGTH = 4096;

function isNonEmptyString(value: unknown): value is string {
    return typeof value === 'string' && value.trim() !== '';
}

// FASE 13 (fix C8): claves de variables estrictas en minúsculas. El regex de
// FASE 12 usaba `/i` y aceptaba mayúsculas; además se rechazan claves que
// colisionan con la runtime o permiten prototype pollution.
const VARIABLE_KEY_RE = /^[a-z][a-z0-9_]*$/;
const MAX_VARIABLE_KEY_LENGTH = 64;
const DANGEROUS_EXACT_KEYS = ['constructor', 'prototype'];

export function isValidVariableKey(value: string): boolean {
    return (
        value.length > 0 &&
        value.length <= MAX_VARIABLE_KEY_LENGTH &&
        VARIABLE_KEY_RE.test(value) &&
        !value.includes('__') &&
        !DANGEROUS_EXACT_KEYS.includes(value)
    );
}

// FASE 13 (UNIDAD 4): referencia `{{...}}` (espejo del `TOKEN_PATTERN` de
// `VariableResolver`; el filtro `|default:'...'` se ignora).
const VARIABLE_REFERENCE_RE = /\{\{\s*([a-z][a-z0-9_.]*)[^}]*\}\}/gi;

export const VARIABLE_NAMESPACES = ['contact', 'business', 'conversation', 'custom'] as const;

/**
 * Campos públicos del negocio (espejo de `BusinessProfile::PUBLIC_FIELDS`).
 * La autoridad es el backend: aquí solo se anticipan warnings de UX.
 */
export const BUSINESS_PUBLIC_FIELDS = ['name', 'description', 'category', 'address', 'website', 'email', 'phone'] as const;

function extractVariableReferences(text: string): string[] {
    const references: string[] = [];

    for (const match of text.matchAll(VARIABLE_REFERENCE_RE)) {
        const key = match[1].toLowerCase();

        if (references.indexOf(key) === -1) {
            references.push(key);
        }
    }

    return references;
}

/**
 * Warnings (NUNCA errores) de referencias a variables que el motor no podrá
 * resolver en el flujo actual. No bloquean la edición ni el guardado; el
 * backend sigue siendo la autoridad (los textos con refs desconocidas se
 * envían tal cual / vacías).
 *
 * @param  ReadonlySet<string>  $customKeys  claves `custom.*` capturadas por
 *        nodos `question` del propio flujo.
 */
export function variableReferenceWarnings(
    type: FlowNodeType,
    config: Record<string, unknown> | null,
    customKeys: ReadonlySet<string>,
): string[] {
    const c = config ?? {};
    const text =
        type === 'message' || type === 'buttons'
            ? (typeof c.text === 'string' ? c.text : '')
            : type === 'question'
              ? (typeof c.prompt === 'string' ? c.prompt : '')
              : '';

    const warnings: string[] = [];

    for (const key of extractVariableReferences(text)) {
        const parts = key.split('.');
        const namespace = parts[0];
        const rest = parts.slice(1).join('.');
        const token = `{{${key}}}`;

        let message: string | null = null;

        if ((VARIABLE_NAMESPACES as readonly string[]).includes(namespace) === false) {
            message = `"${token}" usa un namespace desconocido ("${namespace}"); solo se soportan contact, business, conversation y custom.`;
        } else if (namespace === 'custom' && !customKeys.has(rest)) {
            message = `"${token}" no se captura en ningún nodo "pregunta" de este flujo.`;
        } else if (namespace === 'business' && !(BUSINESS_PUBLIC_FIELDS as readonly string[]).includes(rest)) {
            message = `"${token}" no es un campo público del negocio.`;
        } else if (namespace === 'conversation' && rest !== 'id') {
            message = `"${token}" no existe en la conversación (solo conversation.id).`;
        }

        if (message !== null && warnings.indexOf(message) === -1) {
            warnings.push(message);
        }
    }

    return warnings;
}

/**
 * Anticipa errores de config del nodo (espejo de `FlowValidator::validateNodeConfig`).
 * Devuelve mensajes en español listos para mostrar en el panel.
 */
export function configIssuesForNode(type: FlowNodeType, config: Record<string, unknown> | null): string[] {
    const c = config ?? {};
    const issues: string[] = [];

    switch (type) {
        case 'message':
            if (!isNonEmptyString(c.text)) {
                issues.push('Falta el texto del mensaje.');
            }
            break;

        case 'buttons': {
            if (!isNonEmptyString(c.text)) {
                issues.push('Falta el texto del mensaje.');
            }
            const buttons = Array.isArray(c.buttons) ? c.buttons : [];
            if (buttons.length < 1 || buttons.length > 3) {
                issues.push('Se requieren entre 1 y 3 botones.');
            } else {
                const ids: string[] = [];
                for (const button of buttons) {
                    const b = typeof button === 'object' && button !== null ? (button as Record<string, unknown>) : {};
                    if (!isNonEmptyString(b.id) || !isNonEmptyString(b.title)) {
                        issues.push('Cada botón requiere "id" y "title".');
                        break;
                    }
                    ids.push(String(b.id));
                }
                if (new Set(ids).size !== ids.length && ids.length > 0) {
                    issues.push('Los ids de los botones no pueden repetirse.');
                }
            }
            break;
        }

        case 'question':
            if (!isNonEmptyString(c.prompt)) {
                issues.push('Falta la pregunta (prompt).');
            }
            if (typeof c.field !== 'string' || !isValidVariableKey(c.field)) {
                issues.push('El campo debe ser un nombre de variable válido (ej: nombre).');
            }
            break;

        case 'condition': {
            const rules = Array.isArray(c.rules) ? c.rules : [];
            if (rules.length === 0) {
                issues.push('Se requiere al menos una regla.');
            }
            for (const rule of rules) {
                const r = typeof rule === 'object' && rule !== null ? (rule as Record<string, unknown>) : {};
                if (!isNonEmptyString(r.field)) {
                    issues.push('Cada regla requiere un campo (field).');
                    continue;
                }
                const operator = typeof r.operator === 'string' ? r.operator : '';
                if (!CONDITION_OPERATORS.some((op) => op.value === operator)) {
                    issues.push(`Operador desconocido: "${operator}".`);
                } else if (conditionOperatorNeedsValue(operator) && !Object.prototype.hasOwnProperty.call(r, 'value')) {
                    issues.push('Falta el valor de comparación.');
                }
            }
            break;
        }

        case 'delay': {
            const seconds = c.seconds;
            if (typeof seconds !== 'number' || !Number.isInteger(seconds) || seconds < 1 || seconds > 3600) {
                issues.push('Los segundos deben ser un entero entre 1 y 3600.');
            }
            break;
        }

        case 'tag': {
            const tags = Array.isArray(c.tags) ? c.tags : [];
            if (tags.length < 1 || tags.length > 10) {
                issues.push('Se requieren entre 1 y 10 etiquetas.');
            } else if (tags.some((tag) => !isNonEmptyString(tag))) {
                issues.push('Las etiquetas no pueden estar vacías.');
            }
            break;
        }

        case 'webhook': {
            const url = typeof c.url === 'string' ? c.url.trim() : '';
            if (url === '' || !/^https?:\/\/.+/.test(url)) {
                issues.push('Se requiere una URL http(s) válida.');
            }
            const method = typeof c.method === 'string' ? c.method.toUpperCase() : '';
            if (!HTTP_METHODS.includes(method)) {
                issues.push('Método HTTP inválido.');
            }
            break;
        }

        case 'human': {
            if (c.handoff_message !== undefined && c.handoff_message !== null && typeof c.handoff_message !== 'string') {
                issues.push('El mensaje de traspaso debe ser texto.');
            } else if (typeof c.handoff_message === 'string' && [...c.handoff_message].length > MAX_TEXT_LENGTH) {
                issues.push('El mensaje de traspaso excede la longitud máxima de texto.');
            }
            break;
        }

        case 'end':
        case 'ai':
            break;
    }

    return issues;
}

export function nodeConfigValid(type: FlowNodeType, config: Record<string, unknown> | null): boolean {
    return configIssuesForNode(type, config).length === 0;
}

/**
 * Issues de grafo detectados localmente (sin llamar al backend). Severidad:
 * los que el backend rechazaría son `error`; las ayudas de completado son
 * `warning`.
 */
export function localGraphIssues(nodes: FlowEditorNode[], edges: FlowEditorEdge[]): EditorValidationIssue[] {
    const issues: EditorValidationIssue[] = [];
    const starts = nodes.filter((node) => node.data.isStart);

    if (starts.length !== 1) {
        issues.push({
            nodeId: null,
            severity: 'error',
            code: 'START_REQUIRED',
            message: starts.length === 0 ? 'El flujo debe tener un nodo de inicio.' : 'Solo puede haber un nodo de inicio.',
        });
    }

    const customKeys = new Set(
        nodes
            .filter((candidate) => candidate.data.type === 'question')
            .map((candidate) => (typeof candidate.data.config?.field === 'string' ? candidate.data.config.field : ''))
            .filter((key) => isValidVariableKey(key)),
    );

    for (const node of nodes) {
        const outgoing = edges.filter((edge) => edge.source === node.id);

        for (const issue of configIssuesForNode(node.data.type, node.data.config)) {
            issues.push({ nodeId: node.id, severity: 'warning', code: 'CONFIG', message: issue });
        }

        for (const warning of variableReferenceWarnings(node.data.type, node.data.config, customKeys)) {
            issues.push({ nodeId: node.id, severity: 'warning', code: 'VARIABLE_REFERENCE', message: warning });
        }

        if (isTerminalNodeType(node.data.type)) {
            if (outgoing.length > 0) {
                issues.push({ nodeId: node.id, severity: 'error', code: 'TERMINAL_OUTGOING', message: 'Este nodo es terminal y no debe tener conexiones salientes.' });
            }
            continue;
        }

        if (node.data.type === 'condition') {
            const trueCount = edges.filter((edge) => edge.source === node.id && (edge.sourceHandle || edge.label || '') === 'true').length;
            const falseCount = edges.filter((edge) => edge.source === node.id && (edge.sourceHandle || edge.label || '') === 'false').length;

            if (trueCount !== 1 || falseCount !== 1) {
                issues.push({
                    nodeId: node.id,
                    severity: 'error',
                    code: 'CONDITION_BRANCHES',
                    message: `La condición debe tener una rama "true"${trueCount === 1 ? '' : ' (falta)'} y una "false"${falseCount === 1 ? '' : ' (falta)'}.`,
                });
            }
            continue;
        }

        if (outgoing.length !== 1) {
            issues.push({
                nodeId: node.id,
                severity: 'error',
                code: 'OUTGOING_REQUIRED',
                message: outgoing.length === 0 ? 'Este nodo necesita una conexión saliente.' : 'Este nodo solo puede tener una conexión saliente.',
            });
        }
    }

    if (!nodes.some((node) => node.data.type === 'end' || node.data.type === 'human')) {
        issues.push({ nodeId: null, severity: 'warning', code: 'END_MISSING', message: 'El flujo debería terminar en un nodo "Fin" o "Transferir a humano".' });
    }

    return issues;
}

const NODE_NAME_RE = /El nodo "([^"]+)"/;

/**
 * Mapea los errores del backend (`FlowValidator`) a issues con `nodeId`
 * (si se puede resolver el nodo por nombre) para enfocarlo en el canvas.
 */
export function mapBackendErrors(errors: string[], nodes: FlowEditorNode[]): EditorValidationIssue[] {
    return errors.map((message) => {
        const match = message.match(NODE_NAME_RE);
        const name = match?.[1] ?? null;
        const node = name ? nodes.find((node) => node.data.name === name) : undefined;

        return {
            nodeId: node?.id ?? null,
            severity: 'error',
            code: 'BACKEND',
            message,
        };
    });
}
