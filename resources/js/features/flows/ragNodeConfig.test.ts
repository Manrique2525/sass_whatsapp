import { mount } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { createEditorNode, apiToGraph, graphToDraft } from './flowAdapter';
import { DEFAULT_NODE_CONFIG, type FlowEditorNode } from './flowEditorTypes';
import { configIssuesForNode, localGraphIssues } from './flowValidation';
import { nodeConfigSummary } from './flowUtils';
import AiNodeConfig from './components/panels/config/AiNodeConfig.vue';
import type { NodeConfigContext } from './flowEditorTypes';
import type { FlowNode } from './flowTypes';

/**
 * RAG-V01..V20 — Tests del Knowledge Base Selector para AI Node (FASE 17 U3.5).
 *
 * Unit tests + component tests (con DOM) que verifican:
 * - DEFAULT_NODE_CONFIG incluye knowledge_base_id
 * - Selector renderiza y funciona
 * - Roundtrip adapter preserva knowledge_base_id
 * - Validación acepta null/UUID
 * - Read-only deshabilita selector
 * - Empty/loading/error/deleted KB states
 * - Seguridad: no expone storage fields
 */

// ── helpers ──────────────────────────────────────────────────────────

const context: NodeConfigContext = { tenantId: 'tenant-1', flowId: 'flow-1', readOnly: false };
const readOnlyContext: NodeConfigContext = { tenantId: 'tenant-1', flowId: 'flow-1', readOnly: true };

function aiNode(id: string, config?: Record<string, unknown>): FlowEditorNode {
    return createEditorNode('ai', id, { x: 0, y: 0 }, config ?? { prompt: 'Test', output_variable: 'respuesta' }, 'IA');
}

function installAxios(impl: () => unknown): { get: ReturnType<typeof vi.fn> } {
    const http = { get: vi.fn(impl) } as unknown as Window['axios'];

    window.axios = http;

    return http as unknown as { get: ReturnType<typeof vi.fn> };
}

function mountAiConfig(modelValue: Record<string, unknown> | null = null, ctx: NodeConfigContext = context) {
    return mount(AiNodeConfig, {
        props: { modelValue, context: ctx },
    });
}

const KB_LIST_RESPONSE = {
    data: {
        knowledge_bases: [
            { id: 'kb-001', name: 'FAQ Tienda', description: null, created_at: '2026-01-01', updated_at: '2026-01-01' },
            { id: 'kb-002', name: 'Manual de Productos', description: 'Guía completa', created_at: '2026-01-02', updated_at: '2026-01-02' },
        ],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 },
    },
};

const EMPTY_LIST_RESPONSE = {
    data: {
        knowledge_bases: [],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    },
};

// ── RAG-V01: DEFAULT_NODE_CONFIG includes null KB ────────────────────

describe('RAG-V01 — DEFAULT_NODE_CONFIG includes knowledge_base_id', () => {
    it('ai config includes knowledge_base_id: null', () => {
        expect(DEFAULT_NODE_CONFIG.ai).toHaveProperty('knowledge_base_id');
        expect(DEFAULT_NODE_CONFIG.ai.knowledge_base_id).toBeNull();
    });

    it('existing fields preserved', () => {
        expect(DEFAULT_NODE_CONFIG.ai.prompt).toBe('');
        expect(DEFAULT_NODE_CONFIG.ai.system_prompt).toBe('');
        expect(DEFAULT_NODE_CONFIG.ai.output_variable).toBe('');
        expect(DEFAULT_NODE_CONFIG.ai.fallback_message).toBe('');
    });
});

// ── RAG-V02: Adapter roundtrip preserves knowledge_base_id ───────────

describe('RAG-V02 — Adapter roundtrip preserves knowledge_base_id', () => {
    it('UUID preserved through apiToGraph → graphToDraft', () => {
        const apiNodes: FlowNode[] = [
            {
                id: 'n1',
                type: 'ai',
                type_label: 'IA',
                name: 'IA',
                position_x: 0,
                position_y: 0,
                config: { prompt: 'Test', output_variable: 'out', knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000' },
                is_start: false,
            },
        ];

        const { nodes } = apiToGraph(apiNodes, []);
        const draft = graphToDraft(nodes, [], null);

        expect(draft.nodes[0].config).toEqual(
            expect.objectContaining({ knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000' }),
        );
    });

    it('null preserved through apiToGraph → graphToDraft', () => {
        const apiNodes: FlowNode[] = [
            {
                id: 'n1',
                type: 'ai',
                type_label: 'IA',
                name: 'IA',
                position_x: 0,
                position_y: 0,
                config: { prompt: 'Test', output_variable: 'out' },
                is_start: false,
            },
        ];

        const { nodes } = apiToGraph(apiNodes, []);
        const draft = graphToDraft(nodes, [], null);

        // Old flows without knowledge_base_id: config passes through as-is
        expect(draft.nodes[0].config).toEqual(
            expect.objectContaining({ prompt: 'Test', output_variable: 'out' }),
        );
    });

    it('flow with knowledge_base_id roundtrips correctly', () => {
        const node = aiNode('ai-1', {
            prompt: 'Pregunta',
            output_variable: 'respuesta',
            knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
        });

        const draft = graphToDraft([node], [], null);
        expect(draft.nodes[0].config).toEqual(
            expect.objectContaining({ knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000' }),
        );
    });
});

// ── RAG-V03: Flow validation accepts null KB ─────────────────────────

describe('RAG-V03 — Flow validation accepts null knowledge_base_id', () => {
    it('valid with knowledge_base_id null', () => {
        const issues = configIssuesForNode('ai', { prompt: 'Test', output_variable: 'out', knowledge_base_id: null });
        expect(issues).toHaveLength(0);
    });

    it('valid with knowledge_base_id undefined (old flow)', () => {
        const issues = configIssuesForNode('ai', { prompt: 'Test', output_variable: 'out' });
        expect(issues).toHaveLength(0);
    });

    it('valid with knowledge_base_id UUID', () => {
        const issues = configIssuesForNode('ai', {
            prompt: 'Test',
            output_variable: 'out',
            knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
        });
        expect(issues).toHaveLength(0);
    });
});

// ── RAG-V04: nodeConfigSummary shows KB indicator ────────────────────

describe('RAG-V04 — nodeConfigSummary shows KB indicator', () => {
    it('shows "KB activada" when knowledge_base_id is set', () => {
        const summary = nodeConfigSummary('ai', {
            prompt: 'Pregunta',
            output_variable: 'r',
            knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
        });
        expect(summary).toContain('KB activada');
    });

    it('does not show KB indicator when null', () => {
        const summary = nodeConfigSummary('ai', { prompt: 'Pregunta', output_variable: 'r', knowledge_base_id: null });
        expect(summary).not.toContain('KB activada');
    });

    it('does not show KB indicator when absent (old flow)', () => {
        const summary = nodeConfigSummary('ai', { prompt: 'Pregunta', output_variable: 'r' });
        expect(summary).not.toContain('KB activada');
    });
});

// ── RAG-V05: AiNodeConfig renders KB selector ────────────────────────

describe('RAG-V05 — AiNodeConfig renders KB selector', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders select element with KB label', () => {
        installAxios(() => Promise.resolve(EMPTY_LIST_RESPONSE));
        const wrapper = mountAiConfig({ prompt: 'Test', output_variable: 'out' });

        expect(wrapper.find('select').exists()).toBe(true);
        expect(wrapper.text()).toContain('Base de conocimiento');
    });

    it('emits knowledge_base_id in update', async () => {
        installAxios(() => Promise.resolve(KB_LIST_RESPONSE));
        const wrapper = mountAiConfig({ prompt: 'Test', output_variable: 'out' });

        // Wait for KBs to load
        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        await wrapper.find('select').setValue('kb-001');

        const emitted = wrapper.emitted('update:modelValue') as unknown as [Record<string, unknown>][];
        const last = emitted.at(-1)![0];

        expect(last.knowledge_base_id).toBe('kb-001');
        expect(last.prompt).toBe('Test');
        expect(last.output_variable).toBe('out');
    });
});

// ── RAG-V06: Clear selection → null ───────────────────────────────────

describe('RAG-V06 — Clear selection emits null', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('selecting empty option emits null', async () => {
        installAxios(() => Promise.resolve(KB_LIST_RESPONSE));
        const wrapper = mountAiConfig({
            prompt: 'Test',
            output_variable: 'out',
            knowledge_base_id: 'kb-001',
        });

        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        await wrapper.find('select').setValue('');

        const emitted = wrapper.emitted('update:modelValue') as unknown as [Record<string, unknown>][];
        const last = emitted.at(-1)![0];

        expect(last.knowledge_base_id).toBeNull();
    });
});

// ── RAG-V07: Existing knowledge_base_id renders selected ─────────────

describe('RAG-V07 — Existing knowledge_base_id renders selected', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('select has the correct value when modelValue has knowledge_base_id', async () => {
        installAxios(() => Promise.resolve(KB_LIST_RESPONSE));
        const wrapper = mountAiConfig({
            prompt: 'Test',
            output_variable: 'out',
            knowledge_base_id: 'kb-002',
        });

        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        const select = wrapper.find('select');
        expect((select.element as HTMLSelectElement).value).toBe('kb-002');
    });
});

// ── RAG-V08: Read-only disables selector ─────────────────────────────

describe('RAG-V08 — Read-only disables selector', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('select is disabled when readOnly', async () => {
        installAxios(() => Promise.resolve(KB_LIST_RESPONSE));
        const wrapper = mountAiConfig(
            { prompt: 'Test', output_variable: 'out', knowledge_base_id: 'kb-001' },
            readOnlyContext,
        );

        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('select').attributes('disabled')).toBeDefined();
    });
});

// ── RAG-V09: Loading state ───────────────────────────────────────────

describe('RAG-V09 — Loading state', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('select shows loading text while KBs are loading', async () => {
        // Never resolves — simulates slow load
        installAxios(() => new Promise(() => {}));
        const wrapper = mountAiConfig({ prompt: 'Test', output_variable: 'out' });

        // Reactivity needs a tick to flush after onMounted calls loadKBs
        await wrapper.vm.$nextTick();

        const select = wrapper.find('select');
        expect((select.element as HTMLSelectElement).value).toBe('');
        expect(wrapper.find('select').attributes('disabled')).toBeDefined();
    });
});

// ── RAG-V10: Empty state ─────────────────────────────────────────────

describe('RAG-V10 — Empty state', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows "Sin base de conocimiento" when no KBs exist', async () => {
        installAxios(() => Promise.resolve(EMPTY_LIST_RESPONSE));
        const wrapper = mountAiConfig({ prompt: 'Test', output_variable: 'out' });

        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        const options = wrapper.findAll('select option');
        expect(options.length).toBe(1);
        expect(options[0].text()).toBe('Sin base de conocimiento');
    });
});

// ── RAG-V11: API error preserves existing selection ──────────────────

describe('RAG-V11 — API error preserves existing selection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows error and preserves knowledge_base_id when API fails', async () => {
        installAxios(() => Promise.reject({ response: { data: { message: 'Permission denied' } } }));
        const wrapper = mountAiConfig({
            prompt: 'Test',
            output_variable: 'out',
            knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
        });

        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Permission denied');
        // Model value should not have been mutated
        expect(wrapper.props('modelValue')).toEqual(
            expect.objectContaining({ knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000' }),
        );
    });
});

// ── RAG-V12: Deleted/missing KB state ────────────────────────────────

describe('RAG-V12 — Deleted/missing KB state', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows amber warning when KB in config is not in list', async () => {
        installAxios(() => Promise.resolve(KB_LIST_RESPONSE));
        const wrapper = mountAiConfig({
            prompt: 'Test',
            output_variable: 'out',
            knowledge_base_id: 'deleted-kb-id',
        });

        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Base de conocimiento no disponible');
    });
});

// ── RAG-V13: AI node without KB remains valid ────────────────────────

describe('RAG-V13 — AI node without KB remains valid', () => {
    it('no config issues with knowledge_base_id null', () => {
        const issues = configIssuesForNode('ai', {
            prompt: 'Hola',
            output_variable: 'respuesta',
            knowledge_base_id: null,
        });
        expect(issues).toHaveLength(0);
    });

    it('no config issues with knowledge_base_id absent', () => {
        const issues = configIssuesForNode('ai', { prompt: 'Hola', output_variable: 'respuesta' });
        expect(issues).toHaveLength(0);
    });
});

// ── RAG-V14: No model/provider/API key fields added ──────────────────

describe('RAG-V14 — No semantic settings added', () => {
    it('DEFAULT_NODE_CONFIG.ai does not contain top_k', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('top_k');
    });

    it('DEFAULT_NODE_CONFIG.ai does not contain threshold', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('threshold');
    });

    it('DEFAULT_NODE_CONFIG.ai does not contain embedding_model', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('embedding_model');
    });

    it('DEFAULT_NODE_CONFIG.ai does not contain provider', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('provider');
    });

    it('DEFAULT_NODE_CONFIG.ai does not contain temperature', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('temperature');
    });
});

// ── RAG-V15: graphToDraft preserves UUID ──────────────────────────────

describe('RAG-V15 — graphToDraft preserves knowledge_base_id UUID', () => {
    it('UUID is in the draft nodes config', () => {
        const node = aiNode('ai-1', {
            prompt: 'Pregunta',
            output_variable: 'respuesta',
            knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
        });

        const draft = graphToDraft([node], [], null);

        expect(draft.nodes[0].config).toEqual(
            expect.objectContaining({
                prompt: 'Pregunta',
                output_variable: 'respuesta',
                knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
            }),
        );
    });
});

// ── RAG-V16: Does not expose storage fields ──────────────────────────

describe('RAG-V16 — Does not expose storage fields', () => {
    it('DEFAULT_NODE_CONFIG.ai has no storage_disk', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('storage_disk');
    });

    it('DEFAULT_NODE_CONFIG.ai has no storage_path', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('storage_path');
    });

    it('DEFAULT_NODE_CONFIG.ai has no file_hash', () => {
        expect(DEFAULT_NODE_CONFIG.ai).not.toHaveProperty('file_hash');
    });
});

// ── RAG-V17: Adapter roundtrip with knowledge_base_id set ────────────

describe('RAG-V17 — Full roundtrip AI node with KB', () => {
    it('apiToGraph → mutate config → graphToDraft preserves KB', () => {
        const apiNodes: FlowNode[] = [
            {
                id: 'ai-1',
                type: 'ai',
                type_label: 'IA',
                name: 'Asistente',
                position_x: 100,
                position_y: 200,
                config: {
                    prompt: 'Pregunta al usuario',
                    system_prompt: 'Sos amigable',
                    output_variable: 'respuesta',
                    fallback_message: 'Lo siento',
                    knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
                },
                is_start: false,
            },
        ];

        const { nodes } = apiToGraph(apiNodes, []);

        // Simulate AiNodeConfig update: add knowledge_base_id
        nodes[0].data.config = {
            ...nodes[0].data.config,
            knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
        };

        const draft = graphToDraft(nodes, [], null);

        expect(draft.nodes[0].config).toEqual(
            expect.objectContaining({
                prompt: 'Pregunta al usuario',
                system_prompt: 'Sos amigable',
                output_variable: 'respuesta',
                fallback_message: 'Lo siento',
                knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
            }),
        );
    });
});

// ── RAG-V18: localGraphIssues with AI node + KB ──────────────────────

describe('RAG-V18 — localGraphIssues with AI node + KB', () => {
    it('AI node with KB is valid if connected properly', () => {
        const start = aiNode('start');
        start.data.isStart = true;
        start.data.config = { text: 'Hola' };
        start.data.type = 'message';

        const ai = aiNode('ai-1', {
            prompt: 'Test',
            output_variable: 'out',
            knowledge_base_id: '550e8400-e29b-41d4-a716-446655440000',
        });

        const end = aiNode('end-1', {});
        end.data.type = 'end';

        const edges = [
            { id: 'e-start-ai-1', source: 'start', target: 'ai-1', sourceHandle: undefined, label: undefined },
            { id: 'e-ai-1-end-1', source: 'ai-1', target: 'end-1', sourceHandle: undefined, label: undefined },
        ];

        const issues = localGraphIssues([start, ai, end], edges);
        const aiIssues = issues.filter((i) => i.nodeId === 'ai-1');
        expect(aiIssues).toHaveLength(0);
    });
});

// ── RAG-V19: Optimistic lock unchanged ───────────────────────────────

describe('RAG-V19 — Optimistic lock unchanged by KB', () => {
    it('graphToDraft includes base_updated_at regardless of KB', () => {
        const node = aiNode('ai-1', { prompt: 'Test', output_variable: 'out', knowledge_base_id: 'kb-001' });
        const draft = graphToDraft([node], [], '2026-08-19T12:00:00.000000Z');

        expect(draft.base_updated_at).toBe('2026-08-19T12:00:00.000000Z');
    });

    it('graphToDraft omits base_updated_at when null', () => {
        const node = aiNode('ai-1', { prompt: 'Test', output_variable: 'out', knowledge_base_id: 'kb-001' });
        const draft = graphToDraft([node], [], null);

        expect(draft.base_updated_at).toBeUndefined();
    });
});

// ── RAG-V20: AiNodeConfig preserves existing fields on KB change ─────

describe('RAG-V20 — AiNodeConfig preserves fields on KB change', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('changing KB does not erase prompt/system_prompt/output_variable/fallback', async () => {
        installAxios(() => Promise.resolve(KB_LIST_RESPONSE));
        const wrapper = mountAiConfig({
            prompt: 'Mi prompt',
            system_prompt: 'Mi system',
            output_variable: 'mi_var',
            fallback_message: 'Mi fallback',
            knowledge_base_id: null,
        });

        await vi.dynamicImportSettled();
        await wrapper.vm.$nextTick();

        await wrapper.find('select').setValue('kb-001');

        const emitted = wrapper.emitted('update:modelValue') as unknown as [Record<string, unknown>][];
        const last = emitted.at(-1)![0];

        expect(last.prompt).toBe('Mi prompt');
        expect(last.system_prompt).toBe('Mi system');
        expect(last.output_variable).toBe('mi_var');
        expect(last.fallback_message).toBe('Mi fallback');
        expect(last.knowledge_base_id).toBe('kb-001');
    });
});
