export const MARKETING_EVENTS = [
    'landing_view',
    'landing_cta_clicked',
    'pricing_or_plan_viewed',
    'register_viewed',
    'registration_started',
    'registration_completed',
    'onboarding_viewed',
    'whatsapp_connect_clicked',
    'dashboard_viewed',
] as const;

export type MarketingEventName = (typeof MARKETING_EVENTS)[number];
export type MarketingEventProperties = {
    location?: 'navbar' | 'hero' | 'free_plan' | 'final_cta';
    destination?: 'register' | 'dashboard';
};

export interface MarketingAnalyticsProvider {
    track: (eventName: MarketingEventName, properties: MarketingEventProperties) => void;
}

const noopProvider: MarketingAnalyticsProvider = { track: () => undefined };
const allowedPropertyNames = new Set<keyof MarketingEventProperties>(['location', 'destination']);
let provider: MarketingAnalyticsProvider = noopProvider;
let enabled = import.meta.env.VITE_MARKETING_ANALYTICS_ENABLED === 'true';
const onceEvents = new Set<MarketingEventName>();

export function configureMarketingAnalytics(
    nextProvider: MarketingAnalyticsProvider | null,
    options: { enabled?: boolean } = {},
): void {
    provider = nextProvider ?? noopProvider;
    enabled = options.enabled ?? nextProvider !== null;
    onceEvents.clear();
}

export function trackMarketingEvent(
    eventName: MarketingEventName,
    properties: MarketingEventProperties = {},
    options: { once?: boolean } = {},
): void {
    if (!enabled || (options.once === true && onceEvents.has(eventName))) return;

    if (options.once === true) onceEvents.add(eventName);

    const safeProperties = Object.fromEntries(
        Object.entries(properties).filter(([key, value]) =>
            allowedPropertyNames.has(key as keyof MarketingEventProperties)
            && (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'),
        ),
    ) as MarketingEventProperties;

    try {
        provider.track(eventName, safeProperties);
    } catch {
        // Marketing telemetry must never affect product navigation or actions.
    }
}
