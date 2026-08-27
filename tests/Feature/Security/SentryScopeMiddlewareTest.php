<?php

declare(strict_types=1);

use App\Http\Middleware\SentryScopeMiddleware;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;

uses()->group('security', 'sentry', 'observability');

/**
 * F29-U3-SENTRY-* — SentryScopeMiddleware:
 * vincula request_id y tenant_id al scope de Sentry sin red externa.
 *
 * Se inyecta un Hub con un Scope propio para inspeccionar los tags que
 * el middleware escribe vía \Sentry\configureScope, aplicándolos a un Event.
 */

/** Extrae los tags que quedaron en $scope tras la ejecución del middleware. */
function f29u3_sentry_tags(Scope $scope): array
{
    $event = Event::createEvent();
    $scope->applyToEvent($event);

    return $event->getTags();
}

/** Instala un hub con scope propio como actual y devuelve {scope, original}. */
function f29u3_install_hub(): array
{
    $original = SentrySdk::getCurrentHub();
    $scope = new Scope;
    SentrySdk::setCurrentHub(new Hub(null, $scope));

    return ['scope' => $scope, 'original' => $original];
}

/** Instala un hub con un scope dado (para tests de aislamiento). */
function f29u3_install_hub_with(Scope $scope): void
{
    SentrySdk::setCurrentHub(new Hub(null, $scope));
}

beforeEach(function (): void {
    $this->hubHolder = [];
});

afterEach(function (): void {
    TenantContext::clear();

    if ($this->hubHolder !== []) {
        SentrySdk::setCurrentHub($this->hubHolder['original']);
    }
});

test('F29-U3-SENTRY-01: vincula request_id y tenant_id al scope', function (): void {
    $this->hubHolder = f29u3_install_hub();
    TenantContext::setId('tenant-abc');

    $request = Request::create('/api/v1/leads', 'GET');
    $request->attributes->set('request_id', 'req-123');

    $middleware = new SentryScopeMiddleware;
    $response = $middleware->handle($request, fn (Request $r) => response('ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and(f29u3_sentry_tags($this->hubHolder['scope']))
        ->toMatchArray(['request_id' => 'req-123', 'tenant_id' => 'tenant-abc']);
});

test('F29-U3-SENTRY-02: sin tenant_id no agrega tag tenant_id', function (): void {
    $this->hubHolder = f29u3_install_hub();

    $request = Request::create('/api/v1/leads', 'GET');
    $request->attributes->set('request_id', 'req-456');

    $middleware = new SentryScopeMiddleware;
    $middleware->handle($request, fn (Request $r) => response('ok'));

    $tags = f29u3_sentry_tags($this->hubHolder['scope']);

    expect($tags)->toHaveKey('request_id', 'req-456')
        ->and($tags)->not->toHaveKey('tenant_id');
});

test('F29-U3-SENTRY-03: sin request_id no agrega tag request_id', function (): void {
    $this->hubHolder = f29u3_install_hub();
    TenantContext::setId('tenant-xyz');

    $request = Request::create('/api/v1/leads', 'GET');

    $middleware = new SentryScopeMiddleware;
    $middleware->handle($request, fn (Request $r) => response('ok'));

    $tags = f29u3_sentry_tags($this->hubHolder['scope']);

    expect($tags)->toHaveKey('tenant_id', 'tenant-xyz')
        ->and($tags)->not->toHaveKey('request_id');
});

test('F29-U3-SENTRY-04: dos requests secuenciales usan cada uno su propio scope (sin fuga)', function (): void {
    $scopeA = new Scope;
    $scopeB = new Scope;

    // Request A con request_id/tenant
    f29u3_install_hub_with($scopeA);
    TenantContext::setId('tenant-a');

    $reqA = Request::create('/a');
    $reqA->attributes->set('request_id', 'req-A');
    (new SentryScopeMiddleware)->handle($reqA, fn (Request $r) => response('ok'));

    $tagsA = f29u3_sentry_tags($scopeA);
    expect($tagsA)->toMatchArray(['request_id' => 'req-A', 'tenant_id' => 'tenant-a']);

    // Request B con scope limpio y otro tenant
    f29u3_install_hub_with($scopeB);
    TenantContext::setId('tenant-b');

    $reqB = Request::create('/b');
    $reqB->attributes->set('request_id', 'req-B');
    (new SentryScopeMiddleware)->handle($reqB, fn (Request $r) => response('ok'));

    $tagsB = f29u3_sentry_tags($scopeB);
    expect($tagsB)->toMatchArray(['request_id' => 'req-B', 'tenant_id' => 'tenant-b']);

    // Sin contaminación entre scopes
    expect($tagsA)->toMatchArray(['request_id' => 'req-A', 'tenant_id' => 'tenant-a'])
        ->and($tagsB)->not->toMatchArray(['request_id' => 'req-A']);
});
