import { describe, expect, it } from 'vitest';
import { useEditorHistory } from './useEditorHistory';
import type { GraphSnapshot } from './useEditorHistory';
import { createEditorNode } from './flowAdapter';

function snapshot(nodes: number[]): GraphSnapshot {
    return {
        nodes: nodes.map((id) => createEditorNode('message', `n${id}`, { x: id, y: 0 }, {}, `Nodo ${id}`)),
        edges: [],
    };
}

describe('useEditorHistory', () => {
    it('empieza sin undo ni redo', () => {
        const history = useEditorHistory();

        expect(history.canUndo.value).toBe(false);
        expect(history.canRedo.value).toBe(false);
        expect(history.length.value).toBe(0);
    });

    it('undo devuelve el snapshot previo y construye la rama redo', () => {
        const history = useEditorHistory();

        history.push(snapshot([1]));
        const undone = history.undo(snapshot([1, 2]));

        expect(undone?.nodes.map((n) => n.id)).toEqual(['n1']);
        expect(history.canRedo.value).toBe(true);

        const redone = history.redo(snapshot([1]));

        expect(redone?.nodes.map((n) => n.id)).toEqual(['n1', 'n2']);
    });

    it('push descarta la rama redo', () => {
        const history = useEditorHistory();

        history.push(snapshot([1]));
        history.undo(snapshot([1, 2]));
        history.push(snapshot([1, 3]));

        expect(history.canRedo.value).toBe(false);
        expect(history.length.value).toBe(1);
    });

    it('respeta el límite de 50 snapshots', () => {
        const history = useEditorHistory(50);

        for (let i = 0; i < 60; i += 1) {
            history.push(snapshot([i]));
        }

        expect(history.length.value).toBe(50);
    });

    it('clear vacía ambas pilas', () => {
        const history = useEditorHistory();

        history.push(snapshot([1]));
        history.push(snapshot([1, 2]));
        history.clear();

        expect(history.canUndo.value).toBe(false);
        expect(history.canRedo.value).toBe(false);
    });

    it('los snapshots se clonan (no comparte referencias)', () => {
        const history = useEditorHistory();
        const original = snapshot([1]);

        history.push(original);
        original.nodes[0].position.x = 999;
        const undone = history.undo(snapshot([2]));

        expect(undone?.nodes[0].position.x).not.toBe(999);
    });
});
