import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Login from '@/Pages/Auth/Login.vue';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    form: {
        email: '',
        password: '',
        remember: false,
        errors: {} as Record<string, string>,
        processing: false,
        post: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: () => mocks.form,
}));

const mountPage = () => mount(Login, {
    global: {
        stubs: {
            AuthLayout: { template: '<div><slot /></div>', props: ['title'] },
        },
    },
});

describe('Login page', () => {
    beforeEach(() => {
        mocks.form.email = '';
        mocks.form.password = '';
        mocks.form.remember = false;
        mocks.form.errors = {};
        mocks.form.processing = false;
        mocks.form.post = mocks.post;
        vi.clearAllMocks();
    });

    it('renders the login form fields and action', () => {
        const wrapper = mountPage();

        expect(wrapper.find('input[type="email"]').exists()).toBe(true);
        expect(wrapper.find('input[type="password"]').exists()).toBe(true);
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true);
        expect(wrapper.get('button[type="submit"]').text()).toContain('Iniciar sesión');
    });

    it('submits through Inertia with the login route', async () => {
        const wrapper = mountPage();

        await wrapper.get('input[type="email"]').setValue('agent@example.test');
        await wrapper.get('input[type="password"]').setValue('secret');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(mocks.post).toHaveBeenCalledWith('/login');
        expect(mocks.form.email).toBe('agent@example.test');
        expect(mocks.form.remember).toBe(true);
    });

    it('shows server validation errors', () => {
        mocks.form.errors = { email: 'Las credenciales no son válidas.' };

        expect(mountPage().text()).toContain('Las credenciales no son válidas.');
    });

    it('disables submit while processing', () => {
        mocks.form.processing = true;

        expect(mountPage().get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});
