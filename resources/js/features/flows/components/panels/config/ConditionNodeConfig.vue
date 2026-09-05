<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import type { NodeConfigContext } from '../../../flowEditorTypes';
import { useVariableCatalog } from '../../../useVariableCatalog';
import { CONDITION_OPERATORS } from '../../../flowValidation';
import AppSelect from '@/Components/AppSelect.vue';

interface RuleDraft {
    field: string;
    operator: string;
    value: string;
    not?: boolean;
}

const props = defineProps<{ modelValue: Record<string, unknown> | null; context: NodeConfigContext }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const catalog = useVariableCatalog({ tenantId: props.context.tenantId, flowId: props.context.flowId });

const rules = ref<RuleDraft[]>([]);

function mapRules(value: Record<string, unknown> | null): RuleDraft[] {
    return Array.isArray(value?.rules)
        ? (value.rules as unknown[]).map((rule) => {
              const r = typeof rule === 'object' && rule !== null ? (rule as Record<string, unknown>) : {};

              return {
                  field: String(r.field ?? ''),
                  operator: typeof r.operator === 'string' ? r.operator : 'equals',
                  value: r.value === undefined || r.value === null ? '' : String(r.value),
                  not: typeof r.not === 'boolean' ? r.not : undefined,
              };
          })
        : [{ field: '', operator: 'equals', value: '' }];
}

function syncRules(): void {
    rules.value = mapRules(props.modelValue);
}

watch(
    () => props.modelValue,
    () => syncRules(),
);

syncRules();

onMounted(() => {
    void catalog.load();
});

function update(): void {
    const next: Record<string, unknown> = {
        rules: rules.value.map((rule) =>
            CONDITION_OPERATORS.find((op) => op.value === rule.operator)?.needsValue === false
                ? { field: rule.field, operator: rule.operator, ...(rule.not === true ? { not: true } : {}) }
                : {
                      field: rule.field,
                      operator: rule.operator,
                      value: rule.value,
                      ...(rule.not === true ? { not: true } : {}),
                  },
        ),
    };

    if (props.modelValue && typeof props.modelValue.match === 'string') {
        next.match = props.modelValue.match;
    }

    emit('update:modelValue', next);
}

function addRule(): void {
    rules.value = [...rules.value, { field: '', operator: 'equals', value: '' }];
    update();
}

function removeRule(index: number): void {
    rules.value = rules.value.filter((_, i) => i !== index);
    update();
}

function toggleNot(index: number): void {
    rules.value = rules.value.map((rule, i) => (i === index ? { ...rule, not: rule.not === true ? undefined : true } : rule));
    update();
}

function operatorNeedsValue(operator: string): boolean {
    return CONDITION_OPERATORS.find((op) => op.value === operator)?.needsValue !== false;
}

const fieldOptions = computed<{ value: string; label: string }[]>(() => {
    const options: { value: string; label: string }[] = [];
    const known = new Set<string>();

    for (const group of catalog.groups.value) {
        for (const variable of group.items) {
            known.add(variable.key);
            options.push({ value: variable.key, label: variable.key });
        }
    }

    for (const rule of rules.value) {
        if (rule.field !== '' && !known.has(rule.field)) {
            known.add(rule.field);
            options.push({ value: rule.field, label: `${rule.field} (no está en el catálogo)` });
        }
    }

    return options;
});
</script>

<template>
    <div class="space-y-3">
        <span class="mb-1 block text-xs font-medium text-zinc-600">
            Reglas ({{ props.modelValue?.match === 'any' ? 'se cumple alguna' : 'todas se cumplen' }})
        </span>

        <div v-for="(rule, index) in rules" :key="index" class="space-y-2 rounded-md border border-zinc-200 p-2">
            <div class="flex gap-2">
                <AppSelect
                    v-model="rule.field"
                    class="w-2/5"
                    :options="[
                        { value: '', label: 'Seleccionar variable...' },
                        ...fieldOptions.map((option) => ({ value: option.value, label: option.label })),
                    ]"
                    searchable
                    @change="update"
                />
                <AppSelect
                    v-model="rule.operator"
                    class="w-3/5"
                    :options="CONDITION_OPERATORS.map((op) => ({ value: op.value, label: op.label }))"
                    @change="update"
                />
            </div>
            <div class="flex gap-2">
                <input
                    v-if="operatorNeedsValue(rule.operator)"
                    v-model="rule.value"
                    type="text"
                    class="w-4/5 rounded-md border border-zinc-300 px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none"
                    placeholder="valor de comparación"
                    @input="update"
                />
                <button
                    type="button"
                    class="ml-auto shrink-0 rounded-md border px-2 py-1 text-[11px]"
                    :class="rule.not === true ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-zinc-200 text-zinc-400 hover:bg-zinc-50'"
                    :title="rule.not === true ? 'Regla negada' : 'Negar regla'"
                    @click="toggleNot(index)"
                >
                    not
                </button>
                <button
                    type="button"
                    class="shrink-0 rounded-md px-2 text-xs text-red-500 hover:bg-red-50 disabled:opacity-30"
                    :disabled="rules.length <= 1"
                    @click="removeRule(index)"
                >
                    ✕
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <button
                type="button"
                class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50"
                @click="addRule"
            >
                + Agregar regla
            </button>
            <span v-if="catalog.error.value" class="text-[11px] text-zinc-400">Catálogo no disponible: podés escribir el campo manualmente.</span>
        </div>
    </div>
</template>
