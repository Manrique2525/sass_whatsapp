import { onBeforeUnmount, onMounted, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';

export interface KeyboardHandlers {
    onSave?: () => void;
    onUndo?: () => void;
    onRedo?: () => void;
    onDelete?: () => void;
    onEscape?: () => void;
}

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable;
}

/**
 * Atajos de teclado del editor (FASE 12): Ctrl/Cmd+S guardar, Ctrl/Cmd+Z
 * deshacer, Ctrl/Cmd+Shift+Z / Ctrl/Cmd+Y rehacer, Supr/Backspace eliminar y
 * Escape cancelar selección. Ignora escritura en inputs/textarea.
 */
export function useKeyboardShortcuts(
    handlers: KeyboardHandlers,
    enabled: MaybeRefOrGetter<boolean> = true,
): void {
    function handleKeydown(event: KeyboardEvent): void {
        if (!toValue(enabled)) {
            return;
        }

        if (isTypingTarget(event.target)) {
            return;
        }

        const mod = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        if (mod && key === 's') {
            event.preventDefault();
            handlers.onSave?.();
        } else if (mod && key === 'z') {
            event.preventDefault();
            if (event.shiftKey) {
                handlers.onRedo?.();
            } else {
                handlers.onUndo?.();
            }
        } else if (mod && key === 'y') {
            event.preventDefault();
            handlers.onRedo?.();
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            handlers.onDelete?.();
        } else if (event.key === 'Escape') {
            handlers.onEscape?.();
        }
    }

    onMounted(() => window.addEventListener('keydown', handleKeydown));
    onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
}
