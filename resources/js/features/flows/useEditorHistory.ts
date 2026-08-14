import { computed, ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { FlowEditorEdge, FlowEditorNode } from './flowEditorTypes';

export interface GraphSnapshot {
    nodes: FlowEditorNode[];
    edges: FlowEditorEdge[];
}

/**
 * Historial de undo/redo del editor (FASE 12, ADR-041). Guarda hasta 50
 * snapshots previos; cada `push` descarta la rama redo. `undo`/`redo` reciben
 * el estado actual para reconstruir la pila contraria.
 */
export function useEditorHistory(limit = 50): {
    canUndo: ComputedRef<boolean>;
    canRedo: ComputedRef<boolean>;
    length: ComputedRef<number>;
    push: (snapshot: GraphSnapshot) => void;
    undo: (current: GraphSnapshot) => GraphSnapshot | null;
    redo: (current: GraphSnapshot) => GraphSnapshot | null;
    clear: () => void;
} {
    const past = ref<GraphSnapshot[]>([]);
    const future = ref<GraphSnapshot[]>([]);

    const canUndo = computed(() => past.value.length > 0);
    const canRedo = computed(() => future.value.length > 0);
    const length = computed(() => past.value.length);

    function clone(snapshot: GraphSnapshot): GraphSnapshot {
        return JSON.parse(JSON.stringify(snapshot)) as GraphSnapshot;
    }

    function push(snapshot: GraphSnapshot): void {
        past.value.push(clone(snapshot));
        if (past.value.length > limit) {
            past.value.shift();
        }
        future.value = [];
    }

    function undo(current: GraphSnapshot): GraphSnapshot | null {
        if (past.value.length === 0) {
            return null;
        }

        future.value.push(clone(current));
        const previous = past.value.pop();

        return previous ? clone(previous) : null;
    }

    function redo(current: GraphSnapshot): GraphSnapshot | null {
        if (future.value.length === 0) {
            return null;
        }

        past.value.push(clone(current));
        const next = future.value.pop();

        return next ? clone(next) : null;
    }

    function clear(): void {
        past.value = [];
        future.value = [];
    }

    return { canUndo, canRedo, length, push, undo, redo, clear };
}
