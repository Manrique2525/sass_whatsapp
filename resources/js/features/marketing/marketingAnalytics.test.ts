import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    configureMarketingAnalytics,
    MARKETING_EVENTS,
    trackMarketingEvent,
} from './marketingAnalytics';

describe('marketing analytics', () => {
    const track = vi.fn();

    beforeEach(() => {
        track.mockReset();
        configureMarketingAnalytics({ track }, { enabled: true });
    });

    it('emits the stable funnel taxonomy', () => {
        for (const eventName of MARKETING_EVENTS) trackMarketingEvent(eventName);

        expect(track.mock.calls.map(([eventName]) => eventName)).toEqual(MARKETING_EVENTS);
    });

    it('emits landing CTA context without unsafe properties', () => {
        trackMarketingEvent('landing_cta_clicked', {
            location: 'hero',
            destination: 'register',
            email: 'private@example.test',
            name: 'Private User',
            tenant_id: 'tenant-a',
        } as never);

        expect(track).toHaveBeenCalledWith('landing_cta_clicked', {
            location: 'hero',
            destination: 'register',
        });
        expect(JSON.stringify(track.mock.calls)).not.toMatch(/private|tenant-a|email|name/i);
    });

    it('fires once when requested', () => {
        trackMarketingEvent('landing_view', {}, { once: true });
        trackMarketingEvent('landing_view', {}, { once: true });

        expect(track).toHaveBeenCalledTimes(1);
    });

    it('does not send form or product data for registration events', () => {
        trackMarketingEvent('registration_started');
        trackMarketingEvent('registration_completed');
        trackMarketingEvent('onboarding_viewed');
        trackMarketingEvent('whatsapp_connect_clicked');

        expect(track.mock.calls).toEqual([
            ['registration_started', {}],
            ['registration_completed', {}],
            ['onboarding_viewed', {}],
            ['whatsapp_connect_clicked', {}],
        ]);
    });

    it('is disabled by default when no provider is configured', () => {
        configureMarketingAnalytics(null, { enabled: false });

        expect(() => trackMarketingEvent('dashboard_viewed')).not.toThrow();
        expect(track).not.toHaveBeenCalled();
    });

    it('swallows provider failures', () => {
        configureMarketingAnalytics({ track: vi.fn(() => { throw new Error('provider unavailable'); }) }, { enabled: true });

        expect(() => trackMarketingEvent('dashboard_viewed')).not.toThrow();
    });
});
