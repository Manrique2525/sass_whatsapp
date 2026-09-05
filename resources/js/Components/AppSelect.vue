<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export interface AppSelectOption {
    value: unknown;
    label: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<{
    modelValue: unknown;
    options: AppSelectOption[];
    placeholder?: string;
    disabled?: boolean;
    multiple?: boolean;
    searchable?: boolean;
    clearable?: boolean;
    required?: boolean;
    id?: string;
    name?: string;
    ariaLabel?: string;
    error?: string | null;
}>(), {
    placeholder: 'Seleccionar...',
    disabled: false,
    multiple: false,
    searchable: false,
    clearable: false,
    required: false,
    id: undefined,
    name: undefined,
    ariaLabel: undefined,
    error: null,
});

defineOptions({ inheritAttrs: false });

const emit = defineEmits<{
    (event: 'update:modelValue', value: unknown): void;
    (event: 'change', value: unknown): void;
}>();

const open = ref(false);
const search = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
const highlightedIndex = ref(0);

const selectedValues = computed<unknown[]>(() => props.multiple && Array.isArray(props.modelValue)
    ? props.modelValue
    : [props.modelValue],
);

const isSelected = (option: AppSelectOption): boolean => selectedValues.value.some((value) => Object.is(value, option.value));

const filteredOptions = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    if (query === '') return props.options;
    return props.options.filter((option) => option.label.toLocaleLowerCase().includes(query));
});

watch(filteredOptions, () => {
    highlightedIndex.value = Math.min(highlightedIndex.value, Math.max(0, filteredOptions.value.length - 1));
});

const selectedLabels = computed(() => props.options
    .filter((option) => isSelected(option))
    .map((option) => option.label),
);

const displayLabel = computed(() => selectedLabels.value.length > 0
    ? selectedLabels.value.join(', ')
    : props.placeholder,
);

const nativeValue = computed(() => props.multiple
    ? selectedValues.value.map((value) => String(value ?? ''))
    : String(props.modelValue ?? ''),
);

function emitValue(value: unknown): void {
    emit('update:modelValue', value);
    emit('change', value);
}

function onNativeChange(event: Event): void {
    const target = event.target as HTMLSelectElement;
    const rawValues = props.multiple
        ? Array.from(target.selectedOptions).map((option) => option.value)
        : [target.value];
    const values = rawValues.map((raw) => props.options.find((option) => String(option.value ?? '') === raw)?.value ?? raw);
    emitValue(props.multiple ? values : values[0]);
}

function selectOption(option: AppSelectOption): void {
    if (props.disabled || option.disabled) return;

    if (props.multiple) {
        const values = [...selectedValues.value];
        const index = values.findIndex((value) => Object.is(value, option.value));
        if (index >= 0) values.splice(index, 1);
        else values.push(option.value);
        emitValue(values);
        return;
    }

    emitValue(option.value);
    close();
}

function clear(): void {
    if (props.disabled || props.required || !props.clearable) return;
    emitValue(props.multiple ? [] : '');
    close();
}

function toggle(): void {
    if (props.disabled) return;
    open.value = !open.value;
    if (open.value && props.searchable) {
        void nextTick(() => searchInput.value?.focus());
    }
}

function close(): void {
    open.value = false;
    search.value = '';
}

function moveHighlight(direction: 1 | -1): void {
    if (!open.value) {
        toggle();
        return;
    }

    const count = filteredOptions.value.length;
    if (count > 0) highlightedIndex.value = (highlightedIndex.value + direction + count) % count;
}

function selectHighlighted(): void {
    const option = filteredOptions.value[highlightedIndex.value];
    if (option) selectOption(option);
}

function onKeydown(event: KeyboardEvent): void {
    if (props.disabled) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        if (open.value) selectHighlighted();
        else toggle();
        return;
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveHighlight(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveHighlight(-1);
    }
}

function onSearchKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveHighlight(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveHighlight(-1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        selectHighlighted();
    }
}

function onDocumentPointerdown(event: PointerEvent): void {
    const target = event.target as HTMLElement | null;
    if (target?.closest('[data-app-select]') === null) close();
}

onMounted(() => document.addEventListener('pointerdown', onDocumentPointerdown));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerdown));
</script>

<template>
    <div
        data-app-select
        class="relative min-w-0"
        :class="$attrs.class"
    >
        <input v-if="props.name" type="hidden" :name="props.name" :value="props.multiple ? JSON.stringify(props.modelValue) : String(props.modelValue ?? '')" />
        <select
            class="sr-only"
            :value="nativeValue"
            :multiple="props.multiple"
            :disabled="props.disabled"
            tabindex="-1"
            aria-hidden="true"
            @change="onNativeChange"
        >
            <option v-for="option in props.options" :key="String(option.value)" :value="String(option.value ?? '')" :disabled="option.disabled">
                {{ option.label }}
            </option>
        </select>
        <button
            type="button"
            :id="props.id"
            class="app-select-trigger"
            :class="{ 'app-select-trigger--open': open, 'app-select-trigger--error': props.error }"
            :disabled="props.disabled"
            :aria-label="props.ariaLabel"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :aria-invalid="Boolean(props.error)"
            @click="toggle"
            @keydown="onKeydown"
        >
            <span class="min-w-0 truncate" :class="selectedLabels.length === 0 ? 'text-[#8a9b91]' : 'text-[#33483e]'">
                {{ displayLabel }}
            </span>
            <span class="ml-2 flex shrink-0 items-center gap-1.5 text-[#71877b]">
                <span class="app-select-chevron" :class="{ 'app-select-chevron--open': open }">⌄</span>
            </span>
        </button>
        <button
            v-if="props.clearable && !props.required && selectedLabels.length > 0"
            type="button"
            class="app-select-clear absolute right-8 top-1/2 -translate-y-1/2"
            aria-label="Limpiar selección"
            @click="clear"
        >
            ×
        </button>

        <div v-if="open" class="app-select-menu" role="listbox" :aria-multiselectable="props.multiple || undefined">
            <div v-if="props.searchable" class="border-b border-[#edf2ec] p-2">
                <input
                    ref="searchInput"
                    v-model="search"
                    type="search"
                    class="app-select-search"
                    placeholder="Buscar..."
                    aria-label="Buscar opciones"
                    @keydown.escape.stop="close"
                    @keydown="onSearchKeydown"
                />
            </div>
            <div class="max-h-60 overflow-y-auto p-1">
                <button
                    v-for="(option, index) in filteredOptions"
                    :key="String(option.value)"
                    type="button"
                    class="app-select-option"
                    :class="{
                        'app-select-option--selected': isSelected(option),
                        'app-select-option--highlighted': index === highlightedIndex,
                    }"
                    :disabled="option.disabled"
                    role="option"
                    :aria-selected="isSelected(option)"
                    @click="selectOption(option)"
                >
                    <span class="min-w-0 truncate">{{ option.label }}</span>
                    <span v-if="isSelected(option)" aria-hidden="true" class="text-[#0b8f5a]">✓</span>
                </button>
                <p v-if="filteredOptions.length === 0" class="px-3 py-3 text-center text-xs text-[#71877b]">Sin resultados</p>
            </div>
        </div>
        <p v-if="props.error" class="mt-1 text-xs text-[#b42318]">{{ props.error }}</p>
    </div>
</template>
