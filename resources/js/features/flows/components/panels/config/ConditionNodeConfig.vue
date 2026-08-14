<script setup lang="ts">
import { ref, watch } from 'vue';
import { CONDITION_OPERATORS } from '../../../flowValidation';

interface RuleDraft {
    field: string;
    operator: string;
    value: string;
}

const props = defineProps<{ modelValue: Record<string, unknown> | null }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Record<string, unknown>): void }>();

const rules = ref<RuleDraft[]>(
    Array.isArray(props.modelValue?.rules)
        ? (props.modelValue.rules as unknown[]).map((rule) => {
              const r = typeof rule === 'object' && rule !== null ? (rule as Record<string, unknown>) : {};

              return {
                  field: String(r.field ?? ''),
                  operator: typeof r.operator === 'string' ? r.operator : 'equals',
                  value: r.value === undefined || r.value === null ? '' : String(r.value),
              };
          })
        : [{ field: '', operator: 'equals', value: '' }],
);

watch(
    () => props.modelValue,
    (value) => {
        rules.value = Array.isArray(value?.rules)
            ? (value.rules as unknown[]).map((rule) => {
                  const r = typeof rule === 'object' && rule !== null ? (rule as Record<string, unknown>) : {};

                  return {
                      field: String(r.field ?? ''),
                      operator: typeof r.operator === 'string' ? r.operator : 'equals',
                      value: r.value === undefined || r.value === null ? '' : String(r.value),
                  };
              })
            : [{ field: '', operator: 'equals', value: '' }];
    },
);

function update(): void {
    emit('update:modelValue', {
        rules: rules.value.map((rule) =>
            CONDITION_OPERATORS.find((op) => op.value === rule.operator)?.needsValue === false
                ? { field: rule.field, operator: rule.operator }
                : { field: rule.field, operator: rule.operator, value: rule.value },
        ),
    });
}

function addRule(): void {
    rules.value = [...rules.value, { field: '', operator: 'equals', value: '' }];
    update();
}

function removeRule(index: number): void {
    rules.value = rules.value.filter((_, i) => i !== index);
    update();
}

function operatorNeedsValue(operator: string): boolean {
    return CONDITION_OPERATORS.find((op) => op.value === operator)?.needsValue !== false;
}
</script>

<template>
    <div class="space-y-3">
        <span class="mb-1 block text-xs font-medium text-zinc-600">Reglas (todas se cumplen)</span>

        <div v-for="(rule, index) in rules" :key="index" class="space-y-2 rounded-md border border-zinc-200 p-2">
            <div class="flex gap-2">
                <input
                    v-model="rule.field"
                    type="text"
                    class="w-2/5 rounded-md border border-zinc-300 px-2 py-1.5 text-xs font-mono focus:border-emerald-500 focus:outline-none"
                    placeholder="campo"
                    @input="update"
                />
                <select
                    v-model="rule.operator"
                    class="w-3/5 rounded-md border border-zinc-300 px-2 py-1.5 text-xs focus:border-emerald-500 focus:outline-none"
                    @change="update"
                >
                    <option v-for="op in CONDITION_OPERATORS" :key="op.value" :value="op.value">{{ op.label }}</option>
                </select>
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
                    class="ml-auto shrink-0 rounded-md px-2 text-xs text-red-500 hover:bg-red-50 disabled:opacity-30"
                    :disabled="rules.length <= 1"
                    @click="removeRule(index)"
                >
                    ✕
                </button>
            </div>
        </div>

        <button
            type="button"
            class="rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50"
            @click="addRule"
        >
            + Agregar regla
        </button>
    </div>
</template>
