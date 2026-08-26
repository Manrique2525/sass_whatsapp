<?php

declare(strict_types=1);

use App\Infrastructure\Logging\SentryEventScrubber;
use Sentry\Event;
use Sentry\UserDataBag;

uses()->group('logging', 'security', 'sentry');

test('F28-U2-SCRUB-01: strips Authorization header from request', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/test',
        'method' => 'GET',
        'headers' => ['Authorization' => ['Bearer sk-live-secret123']],
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request['headers'])->not->toHaveKey('Authorization');
});

test('F28-U2-SCRUB-02: strips Cookie header from request', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/test',
        'method' => 'GET',
        'headers' => ['Cookie' => ['session=abc123']],
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request['headers'])->not->toHaveKey('Cookie');
});

test('F28-U2-SCRUB-03: strips X-Hub-Signature-256 header', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/webhooks/whatsapp',
        'method' => 'POST',
        'headers' => ['X-Hub-Signature-256' => ['sha256=abcdef123456']],
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request['headers'])->not->toHaveKey('X-Hub-Signature-256');
});

test('F28-U2-SCRUB-04: strips Stripe-Signature header', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/webhooks/stripe',
        'method' => 'POST',
        'headers' => ['Stripe-Signature' => ['t=1234,v1=abcdef']],
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request['headers'])->not->toHaveKey('Stripe-Signature');
});

test('F28-U2-SCRUB-05: strips sensitive query parameters', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/invite?token=secret123&user_id=42',
        'method' => 'GET',
        'query_string' => 'token=secret123&user_id=42',
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request['query_string'])->not->toContain('token=secret123');
    expect($request['query_string'])->toContain('user_id=42');
});

test('F28-U2-SCRUB-06: strips request body for webhook paths', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/webhooks/whatsapp',
        'method' => 'POST',
        'headers' => ['Content-Type' => ['application/json']],
        'data' => ['entry' => [['messaging' => [['message' => ['text' => 'hello']]]]]],
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request)->not->toHaveKey('data');
});

test('F28-U2-SCRUB-07: strips request body for /login path', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/login',
        'method' => 'POST',
        'headers' => ['Content-Type' => ['application/json']],
        'data' => ['email' => 'user@example.com', 'password' => 'secret123'],
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request)->not->toHaveKey('data');
});

test('F28-U2-SCRUB-08: preserves request body for non-sensitive paths', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com/api/v1/messages',
        'method' => 'POST',
        'headers' => ['Content-Type' => ['application/json']],
        'data' => ['content' => 'hello world'],
    ]);

    $result = SentryEventScrubber::scrub($event);

    $request = $result->getRequest();
    expect($request)->toHaveKey('data');
});

test('F28-U2-SCRUB-09: scrubs email PII from extra data', function (): void {
    $event = Event::createEvent();
    $event->setExtra(['user_email' => 'john@example.com', 'context' => 'login failed']);

    $result = SentryEventScrubber::scrub($event);

    $extra = $result->getExtra();
    expect($extra['user_email'])->toBe('[EMAIL]');
    expect($extra['context'])->toBe('login failed');
});

test('F28-U2-SCRUB-10: scrubs phone numbers from extra data', function (): void {
    $event = Event::createEvent();
    $event->setExtra(['phone' => '+5491155551234']);

    $result = SentryEventScrubber::scrub($event);

    $extra = $result->getExtra();
    expect($extra['phone'])->toBe('[PHONE]');
});

test('F28-U2-SCRUB-11: scrubs OpenAI API keys from extra data', function (): void {
    $event = Event::createEvent();
    $event->setExtra(['api_key_used' => 'sk-live-abcdef1234567890abcdef']);

    $result = SentryEventScrubber::scrub($event);

    $extra = $result->getExtra();
    expect($extra['api_key_used'])->toBe('[REDACTED]');
});

test('F28-U2-SCRUB-12: strips user data except id', function (): void {
    $user = new UserDataBag;
    $user->setId('123');
    $user->setEmail('admin@example.com');
    $user->setUsername('johndoe');
    $user->setIpAddress('1.2.3.4');

    $event = Event::createEvent();
    $event->setUser($user);

    $result = SentryEventScrubber::scrub($event);

    $scrubbedUser = $result->getUser();
    expect($scrubbedUser)->not->toBeNull();
    expect($scrubbedUser?->getId())->toBe('123');
    expect($scrubbedUser?->getEmail())->toBeNull();
    expect($scrubbedUser?->getUsername())->toBeNull();
    expect($scrubbedUser?->getIpAddress())->toBeNull();
});
