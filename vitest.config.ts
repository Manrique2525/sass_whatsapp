import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
        globals: false,
        setupFiles: ['resources/js/tests/setup.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json-summary', 'html'],
            include: ['resources/js/**/*.ts', 'resources/js/**/*.vue'],
            exclude: [
                'resources/js/**/*.test.ts',
                'resources/js/**/*.spec.ts',
                'resources/js/tests/**',
                'resources/js/types/**',
                'resources/js/__mocks__/**',
            ],
        },
    },
});
