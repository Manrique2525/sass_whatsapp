import { vi } from 'vitest';

/**
 * Mocks de entorno jsdom necesarios para @vue-flow/core y los composables del
 * editor de flujos (FASE 12). jsdom 30 no implementa ResizeObserver ni
 * matchMedia.
 */
class ResizeObserverMock {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
}

vi.stubGlobal('ResizeObserver', ResizeObserverMock);

Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
});

// Element#scrollTo no existe en jsdom.
Object.defineProperty(Element.prototype, 'scrollTo', {
    writable: true,
    value: vi.fn(),
});
