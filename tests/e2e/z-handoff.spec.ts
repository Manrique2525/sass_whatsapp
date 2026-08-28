import { expect, test } from '@playwright/test';
import { apiGet } from './helpers/auth';
import { CONVERSATION_HANDOFF_ID, TENANT_A_ID, USERS } from './helpers/constants';
import { claimConversation, openConversation, openInbox, sendReply } from './helpers/inbox';

test.describe('Human Handoff E2E-U2', () => {
    test.use({ storageState: `tests/e2e/.auth/${USERS.agentA.storageKey}.json` });

    test('agente ve, reclama, responde y admin reanuda el bot', async ({ page, browser }) => {
        test.setTimeout(120_000);
        await openInbox(page);
        await page.getByRole('tab', { name: /Sin asignar/ }).click();
        await openConversation(page, 'Rosa Handoff');
        await expect(page.getByText('Bot pausado', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reclamar', exact: true })).toBeVisible();

        await claimConversation(page);
        await expect(page.getByText('Atencion humana (vos)', { exact: true })).toBeVisible();
        const detail = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/conversations/${CONVERSATION_HANDOFF_ID}`);
        expect(detail.status()).toBe(200);
        expect((await detail.json()).conversation.bot_paused).toBe(true);

        const body = 'Respuesta E2E handoff';
        await sendReply(page, body);

        const messages = await apiGet(page, `/api/v1/tenants/${TENANT_A_ID}/conversations/${CONVERSATION_HANDOFF_ID}/messages`);
        expect(messages.status()).toBe(200);
        const payload = await messages.json();
        const sent = payload.messages.find((message: { body: string }) => message.body === body);
        expect(sent).toMatchObject({ status: 'sent', direction: 'outbound' });
        expect(sent.provider_message_id).toMatch(/^wamid-e2e-/);

        const adminContext = await browser.newContext({
            baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8082',
            storageState: `tests/e2e/.auth/${USERS.adminA.storageKey}.json`,
        });
        const adminPage = await adminContext.newPage();
        try {
            await openInbox(adminPage);
            await openConversation(adminPage, 'Rosa Handoff');
            const resumeResponse = adminPage.waitForResponse((response) =>
                response.url().includes('/resume-bot') && response.request().method() === 'POST',
            );
            await adminPage.getByRole('button', { name: 'Reanudar bot', exact: true }).click();
            expect((await resumeResponse).status()).toBe(200);
            await expect(adminPage.getByText('Bot activo', { exact: true }).first()).toBeVisible({ timeout: 30_000 });
        } finally {
            await adminContext.close();
        }

        await page.reload();
        await openConversation(page, 'Rosa Handoff');
        await expect(page.getByText('Bot activo', { exact: true }).first()).toBeVisible();
    });
});
