import * as Sentry from '@sentry/vue';
import type { App } from 'vue';

/**
 * Sensitive query parameters stripped from URLs before Sentry ingestion.
 */
const SENSITIVE_QUERY_PARAMS = [
    'token',
    'code',
    'secret',
    'signature',
    'key',
    'invite',
    'api_key',
    'access_token',
    'refresh_token',
    'password',
];

/**
 * PII patterns to scrub from string values in event extra/contexts.
 */
const PII_PATTERNS: Array<[RegExp, string]> = [
    [/sk[-_](?:live|test|proj)?[a-zA-Z0-9\-_]{10,}/g, '[REDACTED]'],
    [/Bearer\s+[a-zA-Z0-9\-_\.]{20,}/g, 'Bearer [REDACTED]'],
    [/\+[1-9]\d{6,14}/g, '[PHONE]'],
    [/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/g, '[EMAIL]'],
];

/**
 * Scrub a URL string: remove sensitive query parameters.
 */
function scrubUrl(url: string): string {
    try {
        const parsed = new URL(url, window.location.origin);
        for (const param of SENSITIVE_QUERY_PARAMS) {
            parsed.searchParams.delete(param);
        }
        return parsed.toString();
    } catch {
        return url;
    }
}

/**
 * Scrub PII patterns from a string value.
 */
function scrubString(value: string): string {
    let result = value;
    for (const [pattern, replacement] of PII_PATTERNS) {
        result = result.replace(pattern, replacement);
    }
    return result;
}

/**
 * Recursively scrub PII from array values.
 */
function scrubValues(data: Record<string, unknown>): Record<string, unknown> {
    const scrubbed: Record<string, unknown> = {};
    for (const [key, value] of Object.entries(data)) {
        if (typeof value === 'string') {
            scrubbed[key] = scrubString(value);
        } else if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
            scrubbed[key] = scrubValues(value as Record<string, unknown>);
        } else {
            scrubbed[key] = value;
        }
    }
    return scrubbed;
}

/**
 * Frontend Sentry event scrubber — strips PII before events leave the browser.
 * Registered via `beforeSend` in Sentry.init().
 * Fail-safe: never throws; best-effort scrubbing.
 */
export function scrubEvent(event: Sentry.ErrorEvent, _hint: Sentry.EventHint): Sentry.ErrorEvent {
    try {
        const req = event.request as Record<string, unknown> | undefined;

        // Scrub URL in request data
        if (req?.url && typeof req.url === 'string') {
            req.url = scrubUrl(req.url);
        }

        // Scrub query string
        if (req?.query_string && typeof req.query_string === 'string') {
            const params = new URLSearchParams(req.query_string);
            for (const param of SENSITIVE_QUERY_PARAMS) {
                params.delete(param);
            }
            req.query_string = params.toString();
        }

        // Strip request headers (Authorization, Cookie, etc.)
        if (req?.headers && typeof req.headers === 'object') {
            const sensitive = [
                'authorization',
                'cookie',
                'set-cookie',
                'x-csrf-token',
                'x-xsrf-token',
            ];
            for (const key of Object.keys(req.headers as Record<string, string>)) {
                if (sensitive.includes(key.toLowerCase())) {
                    delete (req.headers as Record<string, string>)[key];
                }
            }
        }

        // Strip form data / request data entirely (may contain passwords, messages)
        if (req?.data) {
            delete req.data;
        }

        // Strip user.email, user.ip_address — keep only id
        const user = event.user as Record<string, unknown> | undefined;
        if (user) {
            const userId = user.id;
            event.user = userId ? { id: String(userId) } : undefined;
        }

        // Scrub PII from extra data
        if (event.extra && typeof event.extra === 'object') {
            event.extra = scrubValues(event.extra as Record<string, unknown>);
        }

        // Scrub PII from contexts
        if (event.contexts && typeof event.contexts === 'object') {
            for (const [name, data] of Object.entries(event.contexts)) {
                if (typeof data === 'object' && data !== null) {
                    event.contexts[name] = scrubValues(data as Record<string, unknown>);
                }
            }
        }

        // Strip message content that may contain conversation text
        if (event.message && typeof event.message === 'string') {
            event.message = scrubString(event.message);
        }
    } catch {
        // Fail-safe: never break error reporting
    }

    return event;
}

/**
 * Initialize Sentry for the Vue frontend.
 *
 * - Disabled when VITE_SENTRY_DSN is empty/undefined
 * - No Replay, no browserTracing, no tracing by default
 * - Privacy-first: beforeSend scrubber strips PII
 * - Fail-safe: never blocks app.mount()
 */
export function initSentry(app: App): void {
    const dsn = import.meta.env.VITE_SENTRY_DSN;

    if (!dsn) {
        return;
    }

    try {
        Sentry.init({
            app,
            dsn,
            environment: import.meta.env.VITE_SENTRY_ENVIRONMENT || import.meta.env.MODE || 'development',
            release: import.meta.env.VITE_SENTRY_RELEASE || undefined,
            beforeSend: scrubEvent,
            // No tracesSampleRate = tracing disabled
            // No replayIntegration = replay disabled
            // No browserTracingIntegration = browser tracing disabled
            // vueIntegration is auto-included by Sentry.init()
            // globalHandlersIntegration is auto-included (unhandledrejection, onerror)
            // breadcrumbsIntegration is auto-included (fetch, console, DOM)
            // dedupeIntegration is auto-included
            // inboundFiltersIntegration is auto-included (ignoreErrors, denyUrls)
            ignoreErrors: [
                // Browser noise
                'ResizeObserver loop',
                'ResizeObserver loop completed with undelivered notifications',
                'Non-Error promise rejection captured',
                // AbortController canceled requests
                'AbortError',
                'CanceledError',
            ],
            // Disable source maps in production build unless explicitly configured
            // Vite handles source maps via build.sourcemap in vite.config.js
        });
    } catch {
        // Fail-safe: Sentry init failure must never block app.mount()
    }
}
