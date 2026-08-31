import { expect, test } from '@playwright/test';
import { apiPost } from './helpers/auth';
import { CONVERSATION_REALTIME_ID, TENANT_A_ID, TENANT_B_ID, USERS } from './helpers/constants';
import { observeReverb } from './helpers/realtime';
import { claimConversation, openConversation, openInbox, sendReply } from './helpers/inbox';

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8082';

test.describe('Realtime E2E-U3', () => {
    test('autentica canales privados y rechaza tenant incorrecto', async ({ browser }) => {
        const agentContext = await browser.newContext({
            baseURL,
            storageState: `tests/e2e/.auth/${USERS.agentA.storageKey}.json`,
        });
        const agentPage = await agentContext.newPage();

        await agentPage.goto('/dashboard');
        const own = await apiPost(agentPage, '/broadcasting/auth', {
            socket_id: '1000.1000',
            channel_name: `private-tenant.${TENANT_A_ID}.inbox`,
        });
        expect(own.status()).toBe(200);

        const foreign = await apiPost(agentPage, '/broadcasting/auth', {
            socket_id: '1000.1001',
            channel_name: `private-tenant.${TENANT_B_ID}.inbox`,
        });
        expect(foreign.status()).toBe(403);

        const anonymousContext = await browser.newContext({ baseURL });
        try {
            const anonymousPage = await anonymousContext.newPage();
            const unauthenticated = await anonymousPage.request.post('/broadcasting/auth', {
                headers: {
                    Origin: baseURL,
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                data: {
                    socket_id: '1000.1002',
                    channel_name: `private-tenant.${TENANT_A_ID}.inbox`,
                },
            });
            expect([401, 403]).toContain(unauthenticated.status());
        } finally {
            await anonymousContext.close();
            await agentContext.close();
        }
    });

    test('propaga claim, reply y resume entre dos contextos y aísla tenant B', async ({ browser }) => {
        test.setTimeout(180_000);

        const agentContext = await browser.newContext({
            baseURL,
            storageState: `tests/e2e/.auth/${USERS.agentA.storageKey}.json`,
        });
        const ownerContext = await browser.newContext({
            baseURL,
            storageState: `tests/e2e/.auth/${USERS.ownerA.storageKey}.json`,
        });
        const tenantBContext = await browser.newContext({
            baseURL,
            storageState: `tests/e2e/.auth/${USERS.ownerB.storageKey}.json`,
        });

        const agentPage = await agentContext.newPage();
        const ownerPage = await ownerContext.newPage();
        const tenantBPage = await tenantBContext.newPage();
        const agentRealtime = observeReverb(agentPage);
        const ownerRealtime = observeReverb(ownerPage);
        const tenantBRealtime = observeReverb(tenantBPage);

        try {
            await Promise.all([openInbox(agentPage), openInbox(ownerPage), openInbox(tenantBPage)]);
            await Promise.all([
                agentRealtime.waitUntilConnected(),
                ownerRealtime.waitUntilConnected(),
                tenantBRealtime.waitUntilConnected(),
            ]);

            await openConversation(ownerPage, 'Luna Realtime');
            await openConversation(agentPage, 'Luna Realtime');
            await expect(tenantBPage.getByRole('button', { name: /Luna Realtime/ })).toHaveCount(0);

            const ownerFramesBeforeClaim = ownerRealtime.frameCount();
            await claimConversation(agentPage);
            await ownerRealtime.waitForFrameAfter(ownerFramesBeforeClaim);
            await expect(ownerPage.getByText('Atencion humana (E2E Agent A)', { exact: true })).toBeVisible({ timeout: 30_000 });
            await expect(ownerPage.getByRole('button', { name: 'Reclamar', exact: true })).toHaveCount(0);

            const ownerFramesBeforeReply = ownerRealtime.frameCount();
            await sendReply(agentPage, 'Respuesta realtime U3');
            await ownerRealtime.waitForFrameAfter(ownerFramesBeforeReply);
            await expect(ownerPage.getByText('Respuesta realtime U3', { exact: true })).toBeVisible({ timeout: 30_000 });

            const agentFramesBeforeResume = agentRealtime.frameCount();
            const resumeResponse = ownerPage.waitForResponse((response) =>
                response.url().includes('/resume-bot') && response.request().method() === 'POST',
            );
            await ownerPage.getByRole('button', { name: 'Reanudar bot', exact: true }).click();
            expect((await resumeResponse).status()).toBe(200);
            await agentRealtime.waitForFrameAfter(agentFramesBeforeResume);
            await expect(agentPage.getByText('Bot activo', { exact: true }).first()).toBeVisible({ timeout: 30_000 });

            await expect(tenantBPage.getByRole('button', { name: /Luna Realtime/ })).toHaveCount(0);
            await expect(tenantBPage.getByText('Respuesta realtime U3', { exact: true })).toHaveCount(0);

            const detail = await agentPage.request.get(
                `/api/v1/tenants/${TENANT_A_ID}/conversations/${CONVERSATION_REALTIME_ID}`,
                { headers: { Origin: baseURL, Accept: 'application/json' } },
            );
            expect(detail.status()).toBe(200);
            expect((await detail.json()).conversation.bot_paused).toBe(false);
        } finally {
            await Promise.all([agentContext.close(), ownerContext.close(), tenantBContext.close()]);
        }
    });
});
