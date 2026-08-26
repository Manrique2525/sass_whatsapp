import { describe, expect, it } from 'vitest';
import { scrubEvent } from './sentry';

function makeEvent(overrides: Record<string, unknown> = {}): ReturnType<typeof scrubEvent> {
    return {
        type: undefined,
        event_id: 'test-id',
        ...overrides,
    } as ReturnType<typeof scrubEvent>;
}

function makeHint(): Parameters<typeof scrubEvent>[1] {
    return {} as Parameters<typeof scrubEvent>[1];
}

describe('F28-U3-SCRUB — Frontend Sentry Privacy Scrubber', () => {
    it('F28-U3-SCRUB-01: email removed from extra', () => {
        const event = makeEvent({ extra: { user_email: 'john@example.com' } });
        const result = scrubEvent(event, makeHint());
        expect(result.extra?.user_email).toBe('[EMAIL]');
    });

    it('F28-U3-SCRUB-02: phone removed from extra', () => {
        const event = makeEvent({ extra: { phone: '+5491155551234' } });
        const result = scrubEvent(event, makeHint());
        expect(result.extra?.phone).toBe('[PHONE]');
    });

    it('F28-U3-SCRUB-03: token query param removed from URL', () => {
        const event = makeEvent({
            request: {
                url: 'https://example.com/invite?token=secret123&user_id=42',
                query_string: 'token=secret123&user_id=42',
            },
        });
        const result = scrubEvent(event, makeHint());
        expect(result.request?.query_string).toBe('user_id=42');
        expect(result.request?.url).not.toContain('token=secret123');
    });

    it('F28-U3-SCRUB-04: Authorization header removed', () => {
        const event = makeEvent({
            request: {
                headers: {
                    Authorization: 'Bearer sk-live-secret',
                    'Content-Type': 'application/json',
                },
            },
        });
        const result = scrubEvent(event, makeHint());
        expect(result.request?.headers).not.toHaveProperty('Authorization');
        expect(result.request?.headers?.['Content-Type']).toBe('application/json');
    });

    it('F28-U3-SCRUB-05: request data removed', () => {
        const event = makeEvent({
            request: {
                data: { email: 'test@test.com', password: 'secret' },
            },
        });
        const result = scrubEvent(event, makeHint());
        expect(result.request?.data).toBeUndefined();
    });

    it('F28-U3-SCRUB-06: message content scrubbed', () => {
        const event = makeEvent({
            message: 'User john@example.com sent a message',
        });
        const result = scrubEvent(event, makeHint());
        expect(result.message).toBe('User [EMAIL] sent a message');
    });

    it('F28-U3-SCRUB-07: stack trace preserved (message field)', () => {
        const event = makeEvent({
            message: 'TypeError: Cannot read property',
        });
        const result = scrubEvent(event, makeHint());
        expect(result.message).toBe('TypeError: Cannot read property');
    });

    it('F28-U3-SCRUB-08: malformed event does not throw', () => {
        const event = makeEvent({
            request: 'not-an-object',
            extra: 'not-an-object',
            user: 42,
            message: 123,
        });
        expect(() => scrubEvent(event, makeHint())).not.toThrow();
    });

    it('F28-U3-SCRUB-09: API key scrubbed from extra', () => {
        const event = makeEvent({
            extra: { key: 'sk-live-abcdef1234567890abcdef' },
        });
        const result = scrubEvent(event, makeHint());
        expect(result.extra?.key).toBe('[REDACTED]');
    });

    it('F28-U3-SCRUB-10: user keeps only id', () => {
        const event = makeEvent({
            user: { id: '123', email: 'admin@test.com', ip_address: '1.2.3.4' },
        });
        const result = scrubEvent(event, makeHint());
        expect(result.user).toEqual({ id: '123' });
    });

    it('F28-U3-SCRUB-11: nested context values scrubbed', () => {
        const event = makeEvent({
            contexts: { billing: { email: 'pay@example.com', amount: 100 } },
        });
        const result = scrubEvent(event, makeHint());
        expect((result.contexts?.billing as Record<string, unknown>)?.email).toBe('[EMAIL]');
        expect((result.contexts?.billing as Record<string, unknown>)?.amount).toBe(100);
    });

    it('F28-U3-SCRUB-12: CSRF token header removed', () => {
        const event = makeEvent({
            request: {
                headers: {
                    'X-CSRF-TOKEN': 'abc123',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        });
        const result = scrubEvent(event, makeHint());
        expect(result.request?.headers).not.toHaveProperty('X-CSRF-TOKEN');
        expect(result.request?.headers?.['X-Requested-With']).toBe('XMLHttpRequest');
    });
});
