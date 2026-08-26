<?php

declare(strict_types=1);

use App\Infrastructure\Logging\SentryEventScrubber;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Sentry\Event;

uses()->group('logging', 'security', 'sentry');

test('F28-U2-QUEUE-01: config/sentry.php loads with correct defaults', function (): void {
    expect(config('sentry.send_default_pii'))->toBeFalse();
    expect(config('sentry.sample_rate'))->toBe(1.0);
    expect(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse();
    expect(config('sentry.breadcrumbs.http_client_requests'))->toBeFalse();
});

test('F28-U2-QUEUE-02: tracing is disabled by default (opt-in)', function (): void {
    expect(config('sentry.traces_sample_rate'))->toBeNull();
    expect(config('sentry.tracing.queue_job_transactions'))->toBeFalse();
    expect(config('sentry.tracing.sql_queries'))->toBeFalse();
});

test('F28-U2-QUEUE-03: max_request_body_size is none', function (): void {
    expect(config('sentry.max_request_body_size'))->toBe('none');
});

test('F28-U2-QUEUE-04: before_send callback is registered', function (): void {
    expect(config('sentry.before_send'))->toBe([SentryEventScrubber::class, 'scrub']);
});

test('F28-U2-QUEUE-05: ignore_transactions excludes /up', function (): void {
    expect(config('sentry.ignore_transactions'))->toContain('/up');
});

test('F28-U2-QUEUE-06: ignore_exceptions includes expected business exceptions', function (): void {
    $ignored = config('sentry.ignore_exceptions');

    expect($ignored)->toContain(ValidationException::class);
    expect($ignored)->toContain(AuthenticationException::class);
    expect($ignored)->toContain(ModelNotFoundException::class);
});

test('F28-U2-FAIL-01: scrubber returns event (fail-open)', function (): void {
    $event = Event::createEvent();

    $result = SentryEventScrubber::scrub($event);

    expect($result)->toBeInstanceOf(Event::class);
});

test('F28-U2-FAIL-02: scrubber survives invalid request data', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://example.com',
        'method' => 'GET',
        'headers' => 'not-an-array',
        'data' => null,
    ]);

    $result = SentryEventScrubber::scrub($event);

    expect($result)->toBeInstanceOf(Event::class);
});
