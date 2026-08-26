<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * Centralized Sentry event scrubber — strips PII, secrets, and request data
 * before events leave the application.
 *
 * Registered via `before_send` and `before_send_transaction` in config/sentry.php.
 * Fail-safe: never throws; best-effort scrubbing.
 */
final class SentryEventScrubber
{
    /**
     * Headers to strip entirely from Sentry request data.
     *
     * The SDK already filters Authorization/Cookie/Set-Cookie when send_default_pii=false,
     * but we extend the list with webhook signatures and CSRF tokens.
     */
    private const STRIPPED_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'proxy-authorization',
        'x-forwarded-for',
        'x-real-ip',
        'x-hub-signature',
        'x-hub-signature-256',
        'stripe-signature',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    /**
     * Query string parameters to strip (may contain tokens/secrets).
     */
    private const STRIPPED_QUERY_PARAMS = [
        'token',
        'code',
        'secret',
        'signature',
        'key',
        'invite',
        'api_key',
        'access_token',
        'refresh_token',
    ];

    /**
     * Paths where request body should never be captured.
     */
    private const BODY_EXCLUDED_PATHS = [
        '/api/webhooks/whatsapp',
        '/api/webhooks/stripe',
        '/api/webhooks/',
        '/api/v1/flows/webhook/',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/api/v1/invitations/accept',
    ];

    /**
     * PII patterns to scrub from string values in extra/contexts.
     */
    private const PII_PATTERNS = [
        '/sk[-_](?:live|test|proj)?[a-zA-Z0-9\-_]{10,}/' => '[REDACTED]',
        '/Bearer\s+[a-zA-Z0-9\-_\.]{20,}/' => 'Bearer [REDACTED]',
        '/\+[1-9]\d{6,14}/' => '[PHONE]',
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/' => '[EMAIL]',
    ];

    /**
     * Scrub a Sentry event before it is sent.
     */
    public static function scrub(Event $event, ?EventHint $hint = null): Event
    {
        try {
            self::scrubRequest($event);
            self::scrubExtra($event);
            self::scrubContexts($event);
            self::scrubUser($event);
            self::scrubTags($event);
        } catch (\Throwable) {
            // Fail-safe: never break error reporting
        }

        return $event;
    }

    private static function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();

        if (empty($request)) {
            return;
        }

        // Strip sensitive headers
        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = array_filter(
                $request['headers'],
                static fn (string $name): bool => ! in_array(strtolower($name), self::STRIPPED_HEADERS, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        // Strip sensitive query parameters
        if (isset($request['query_string']) && is_string($request['query_string'])) {
            $params = [];
            parse_str($request['query_string'], $params);

            foreach (self::STRIPPED_QUERY_PARAMS as $sensitive) {
                unset($params[$sensitive]);
            }

            $request['query_string'] = http_build_query($params);
        }

        // Strip request body for sensitive paths
        if (isset($request['url']) && is_string($request['url'])) {
            $path = parse_url($request['url'], PHP_URL_PATH) ?: '';
            foreach (self::BODY_EXCLUDED_PATHS as $excluded) {
                if (str_starts_with($path, $excluded)) {
                    unset($request['data']);
                    break;
                }
            }
        }

        // Always strip cookies and env (shouldn't be there with send_default_pii=false, but defensive)
        unset($request['cookies'], $request['env']);

        $event->setRequest($request);
    }

    private static function scrubExtra(Event $event): void
    {
        $extra = $event->getExtra();

        if (empty($extra)) {
            return;
        }

        $event->setExtra(self::scrubStringValues($extra));
    }

    private static function scrubContexts(Event $event): void
    {
        $contexts = $event->getContexts();

        if (empty($contexts)) {
            return;
        }

        foreach ($contexts as $name => $data) {
            $contexts[$name] = self::scrubStringValues($data);
        }

        // Re-set all contexts
        foreach ($contexts as $name => $data) {
            $event->setContext($name, $data);
        }
    }

    private static function scrubUser(Event $event): void
    {
        $user = $event->getUser();

        if ($user === null) {
            return;
        }

        // Only keep id — strip email, name, username, ip
        $id = $user->getId();
        $event->setUser(null);

        if ($id !== null) {
            $newUser = new UserDataBag;
            $newUser->setId((string) $id);
            $event->setUser($newUser);
        }
    }

    private static function scrubTags(Event $event): void
    {
        $tags = $event->getTags();

        // Scrub PII from tag values
        foreach ($tags as $key => $value) {
            $tags[$key] = self::scrubScalar($value);
        }

        $event->setTags($tags);
    }

    /**
     * Recursively scrub PII patterns from array values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function scrubStringValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::scrubScalar($value);
            } elseif (is_array($value)) {
                $data[$key] = self::scrubStringValues($value);
            }
        }

        return $data;
    }

    private static function scrubScalar(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        foreach (self::PII_PATTERNS as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }
}
