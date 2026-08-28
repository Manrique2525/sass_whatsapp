import { expect, test } from '@playwright/test';
import { apiGet } from '../helpers/auth';
import { CONVERSATION_A2_ID, TENANT_A_ID, USERS } from '../helpers/constants';
import { openConversation, openInbox, sendReply } from '../helpers/inbox';

test.describe('Reply E2E-U2', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.agentA.storageKey}.json` });

    test('agente responde y el pipeline sync persiste Sent tras reload', async ({ page }) => {
        const body = 'Respuesta E2E agente';

        await openInbox(page);
        await openConversation(page, 'Juan A2');
        await sendReply(page, body);

        const messages = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/conversations/${CONVERSATION_A2_ID}/messages`);
        expect(messages.status()).toBe(200);
        const payload = await messages.json();
        const sent = payload.messages.find((message: { body: string }) => message.body === body);
        expect(sent).toMatchObject({ status: 'sent', direction: 'outbound' });
        expect(sent.provider_message_id).toMatch(/^wamid-e2e-/);

        await page.reload();
        await openConversation(page, 'Juan A2');
        await expect(page.getByText(body, { exact: true })).toBeVisible();
    });
});
