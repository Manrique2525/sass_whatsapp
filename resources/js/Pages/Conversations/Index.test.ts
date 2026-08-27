import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Index from '@/Pages/Conversations/Index.vue';

const get = vi.fn();
Object.defineProperty(window, 'axios', { value: { get, post: vi.fn() }, writable: true });

const auth = {
    user: { id: 7, name: 'Agent', email: 'agent@example.test' },
    current_tenant_id: 'tenant-a',
    permissions: ['conversations.view', 'messages.send'] as string[],
};

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { auth } }) }));
vi.mock('@/features/realtime/useConversationChannel', () => ({ useConversationChannel: vi.fn() }));
vi.mock('@/features/realtime/useInboxChannel', () => ({ useInboxChannel: vi.fn() }));

const emptyResponse = { data: {
    conversations: [],
    meta: { current_page: 1, last_page: 1, per_page: 30, total: 0 },
    counts: { all: 0, mine: 0, unassigned: 0 },
} };

const mountPage = () => mount(Index, {
    global: {
        stubs: {
            AppLayout: { template: '<div><slot /></div>', props: ['user', 'fullWidth'] },
            ConversationFilters: { template: '<div data-test="filters" />' },
            ConversationListItem: { template: '<div />' },
            ChatHeader: { template: '<div />' },
            ContactPanel: { template: '<div />' },
            MessageComposer: { template: '<div />' },
            MessageList: { template: '<div />' },
        },
    },
});

describe('Inbox permission guard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        auth.permissions = ['conversations.view', 'messages.send'];
        get.mockImplementation((url: string) => {
            if (url.endsWith('/users')) return Promise.resolve({ data: { members: [] } });
            return Promise.resolve(emptyResponse);
        });
    });

    it('F29-U5-INBOX-01: canView=false makes no conversations request', async () => {
        auth.permissions = [];
        mountPage();
        await flushPromises();

        expect(get).not.toHaveBeenCalled();
    });

    it('F29-U5-INBOX-02: canView=false renders the limited state safely', async () => {
        auth.permissions = [];
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.text()).toContain('No tienes permiso para ver conversaciones.');
    });

    it('F29-U5-INBOX-03: canView=true loads conversations', async () => {
        mountPage();
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/v1/tenants/tenant-a/conversations', expect.any(Object));
    });

    it('F29-U5-INBOX-04: canSeeUsers=true loads members', async () => {
        auth.permissions.push('users.view');
        mountPage();
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/api/v1/tenants/tenant-a/users');
    });

    it('F29-U5-INBOX-05: canSeeUsers=false does not load members', async () => {
        mountPage();
        await flushPromises();

        expect(get).not.toHaveBeenCalledWith('/api/v1/tenants/tenant-a/users');
    });
});
