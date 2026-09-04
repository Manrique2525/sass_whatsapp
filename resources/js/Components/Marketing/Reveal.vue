<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ delay?: number }>(), { delay: 0 });

const element = ref<HTMLElement | null>(null);
const visible = ref(true);
const revealPending = ref(false);
let observer: IntersectionObserver | null = null;
let revealTimer: number | null = null;

onMounted(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduced) {
        visible.value = true;
        return;
    }

    if (!('IntersectionObserver' in window)) return;

    try {
        observer = new IntersectionObserver(
            ([entry]) => {
                if (entry?.isIntersecting) {
                    revealTimer = window.setTimeout(() => {
                        visible.value = true;
                        revealPending.value = false;
                    }, props.delay);
                    observer?.disconnect();
                }
            },
            { threshold: 0.12 },
        );
        revealPending.value = true;
    } catch {
        observer = null;
        revealPending.value = false;
        visible.value = true;
    }

    if (element.value && observer) observer.observe(element.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    if (revealTimer !== null) window.clearTimeout(revealTimer);
});
</script>

<template>
    <div ref="element" class="marketing-reveal" :class="{ 'marketing-reveal--pending': revealPending, 'marketing-reveal--visible': visible && !revealPending }">
        <slot />
    </div>
</template>
