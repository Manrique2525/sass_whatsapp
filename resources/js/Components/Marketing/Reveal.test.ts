import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Reveal from '@/Components/Marketing/Reveal.vue';

type ObserverCallback = (entries: Array<{ isIntersecting: boolean }>) => void;

const mountReveal = () => mount(Reveal, { slots: { default: '<p>Contenido de marketing</p>' } });

describe('Marketing Reveal', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: false }));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('uses a pending animation state when IntersectionObserver is available', async () => {
        let callback: ObserverCallback | undefined;
        const Observer = class {
            constructor(next: ObserverCallback) {
                callback = next;
            }

            observe = vi.fn();
            disconnect = vi.fn();
        } as unknown as typeof IntersectionObserver;
        vi.stubGlobal('IntersectionObserver', Observer);

        const wrapper = mountReveal();
        await wrapper.vm.$nextTick();

        expect(wrapper.classes()).toContain('marketing-reveal--pending');
        expect(wrapper.classes()).not.toContain('marketing-reveal--visible');

        callback?.([{ isIntersecting: true }]);
        vi.runAllTimers();
        await wrapper.vm.$nextTick();

        expect(wrapper.classes()).toContain('marketing-reveal--visible');
    });

    it('keeps content visible when IntersectionObserver is unavailable', () => {
        vi.stubGlobal('IntersectionObserver', undefined);

        const wrapper = mountReveal();

        expect(wrapper.classes()).toContain('marketing-reveal--visible');
        expect(wrapper.classes()).not.toContain('marketing-reveal--pending');
    });

    it('keeps content visible when the observer constructor throws', () => {
        vi.stubGlobal('IntersectionObserver', class {
            constructor() {
                throw new Error('observer unavailable');
            }
        } as unknown as typeof IntersectionObserver);

        const wrapper = mountReveal();

        expect(wrapper.classes()).toContain('marketing-reveal--visible');
        expect(wrapper.classes()).not.toContain('marketing-reveal--pending');
    });

    it('keeps content visible with reduced motion', () => {
        vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true }));
        vi.stubGlobal('IntersectionObserver', class {
            constructor() {
                throw new Error('observer should not be needed');
            }
        } as unknown as typeof IntersectionObserver);

        const wrapper = mountReveal();

        expect(wrapper.classes()).toContain('marketing-reveal--visible');
        expect(wrapper.classes()).not.toContain('marketing-reveal--pending');
    });
});
