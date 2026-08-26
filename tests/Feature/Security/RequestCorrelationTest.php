<?php

declare(strict_types=1);

use App\Http\Middleware\RequestCorrelationId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

uses()->group('logging');

test('F28-U1-REQ-01: missing X-Request-ID generates UUID', function (): void {
    $request = Request::create('/test', 'GET');

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, fn () => new Response('ok'));

    $requestId = $response->headers->get('X-Request-ID');

    expect($requestId)->not->toBeNull();
    expect(Str::isUuid($requestId))->toBeTrue();
});

test('F28-U1-REQ-02: valid incoming UUID preserved', function (): void {
    $uuid = (string) Str::uuid();
    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_X_REQUEST_ID' => $uuid,
    ]);

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, fn () => new Response('ok'));

    expect($response->headers->get('X-Request-ID'))->toBe($uuid);
});

test('F28-U1-REQ-03: invalid incoming ID replaced', function (): void {
    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_X_REQUEST_ID' => 'not a valid id!@#$%^&*()',
    ]);

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, fn () => new Response('ok'));

    $requestId = $response->headers->get('X-Request-ID');

    expect($requestId)->not->toBe('not a valid id!@#$%^&*()');
    expect(Str::isUuid($requestId))->toBeTrue();
});

test('F28-U1-REQ-04: oversized incoming ID replaced', function (): void {
    $oversized = str_repeat('a', 200);
    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_X_REQUEST_ID' => $oversized,
    ]);

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, fn () => new Response('ok'));

    $requestId = $response->headers->get('X-Request-ID');

    expect($requestId)->not->toBe($oversized);
    expect(Str::isUuid($requestId))->toBeTrue();
});

test('F28-U1-REQ-05: response contains X-Request-ID', function (): void {
    $request = Request::create('/test', 'GET');

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, fn () => new Response('ok'));

    expect($response->headers->has('X-Request-ID'))->toBeTrue();
    expect($response->headers->get('X-Request-ID'))->not->toBeEmpty();
});

test('F28-U1-REQ-06: request attributes contain request_id', function (): void {
    $request = Request::create('/test', 'GET');

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, function () use ($request): Response {
        expect($request->attributes->get('request_id'))->not->toBeNull();
        expect(Str::isUuid($request->attributes->get('request_id')))->toBeTrue();

        return new Response('ok');
    });

    expect($response)->toBeInstanceOf(Response::class);
});

test('F28-U1-REQ-07: two sequential requests do not leak IDs', function (): void {
    $middleware = new RequestCorrelationId;

    $request1 = Request::create('/test', 'GET');
    $middleware->handle($request1, fn () => new Response('ok'));
    $id1 = $request1->attributes->get('request_id');

    $request2 = Request::create('/test', 'GET');
    $middleware->handle($request2, fn () => new Response('ok'));
    $id2 = $request2->attributes->get('request_id');

    expect($id1)->not->toBe($id2);
});

test('F28-U1-REQ-08: error response still has X-Request-ID', function (): void {
    $request = Request::create('/test', 'GET');

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, fn () => new Response('error', 500));

    expect($response->headers->has('X-Request-ID'))->toBeTrue();
    expect($response->status())->toBe(500);
});

test('F28-U1-REQ-09: safe alphanumeric IDs are accepted', function (): void {
    $safe = 'my-trace-id_123';
    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_X_REQUEST_ID' => $safe,
    ]);

    $middleware = new RequestCorrelationId;
    $response = $middleware->handle($request, fn () => new Response('ok'));

    expect($response->headers->get('X-Request-ID'))->toBe($safe);
});
