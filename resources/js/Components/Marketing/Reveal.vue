<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{ delay?: number }>(), { delay: 0 });

const element = ref<HTMLElement | null>(null);
const visible = ref(false);
let observer: IntersectionObserver | null = null;

onMounted(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduced) {
        visible.value = true;
        return;
    }

    observer = new IntersectionObserver(
        ([entry]) => {
            if (entry?.isIntersecting) {
                window.setTimeout(() => {
                    visible.value = true;
                }, props.delay);
                observer?.disconnect();
            }
        },
        { threshold: 0.12 },
    );

    if (element.value) observer.observe(element.value);
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div ref="element" class="marketing-reveal" :class="{ 'marketing-reveal--visible': visible }">
        <slot />
    </div>
</template>
