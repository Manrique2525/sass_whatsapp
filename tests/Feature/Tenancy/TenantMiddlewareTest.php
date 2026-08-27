<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Http\Middleware\TenantMiddleware;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| TenantMiddleware Tests (FASE 29 U2)
|--------------------------------------------------------------------------
|
| F29-U2-TEN-01..12 — TenantMiddleware failure modes + context leak prevention.
| Maps: handle(), deny(), TenantContext set/clear, 401/403/404 semantics.
|
*/

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->user = User::factory()->create();
    make_tenant_member($this->user, $this->tenant, 'owner');
    $this->user->forceFill(['current_tenant_id' => $this->tenant->id])->save();
});

it('F29-U2-TEN-01: valid tenant + valid member passes through and sets context', function (): void {
    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $response = (new TenantMiddleware)->handle($request, function (Request $r) {
        expect(TenantContext::bound())->toBeTrue()
            ->and(TenantContext::id())->toBe($this->tenant->id);

        return response('', 200);
    });

    expect($response->getStatusCode())->toBe(200);
    expect(TenantContext::bound())->toBeFalse();
})->group('F29-U2-TEN');

it('F29-U2-TEN-02: unauthenticated user gets 403 NO_TENANT on API', function (): void {
    $request = Request::create('/api/v1/test', 'GET');

    $response = (new TenantMiddleware)->handle($request, fn () => response('', 200));

    expect($response->getStatusCode())->toBe(403);
    $json = json_decode($response->getContent(), true);
    expect($json['code'])->toBe('NO_TENANT');
})->group('F29-U2-TEN');

it('F29-U2-TEN-03: unauthenticated user gets 403 on web route', function (): void {
    $request = Request::create('/dashboard', 'GET');

    try {
        (new TenantMiddleware)->handle($request, fn () => response('', 200));
        $this->fail('Expected HttpException 403 to be thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
})->group('F29-U2-TEN');

it('F29-U2-TEN-04: user with null current_tenant_id gets 403 NO_TENANT', function (): void {
    $this->user->forceFill(['current_tenant_id' => null])->save();
    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $response = (new TenantMiddleware)->handle($request, fn () => response('', 200));

    expect($response->getStatusCode())->toBe(403);
    $json = json_decode($response->getContent(), true);
    expect($json['code'])->toBe('NO_TENANT');
})->group('F29-U2-TEN');

it('F29-U2-TEN-05: pending (non-active) membership gets 403 NO_TENANT', function (): void {
    DB::table('tenant_users')
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->user->id)
        ->update(['status' => 'pending']);

    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $response = (new TenantMiddleware)->handle($request, fn () => response('', 200));

    expect($response->getStatusCode())->toBe(403);
    $json = json_decode($response->getContent(), true);
    expect($json['code'])->toBe('NO_TENANT');
})->group('F29-U2-TEN');

it('F29-U2-TEN-06: suspended tenant gets 403 NO_TENANT', function (): void {
    $this->tenant->forceFill(['status' => 'suspended'])->save();
    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $response = (new TenantMiddleware)->handle($request, fn () => response('', 200));

    expect($response->getStatusCode())->toBe(403);
    $json = json_decode($response->getContent(), true);
    expect($json['code'])->toBe('NO_TENANT');
})->group('F29-U2-TEN');

it('F29-U2-TEN-07: user not member of current tenant gets 403 NO_TENANT', function (): void {
    $other = Tenant::factory()->create();
    $this->user->forceFill(['current_tenant_id' => $other->id])->save();
    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $response = (new TenantMiddleware)->handle($request, fn () => response('', 200));

    expect($response->getStatusCode())->toBe(403);
    $json = json_decode($response->getContent(), true);
    expect($json['code'])->toBe('NO_TENANT');
})->group('F29-U2-TEN');

it('F29-U2-TEN-08: context cleared after handler completes', function (): void {
    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    (new TenantMiddleware)->handle($request, function () {
        expect(TenantContext::bound())->toBeTrue();

        return response('', 200);
    });

    expect(TenantContext::bound())->toBeFalse();
})->group('F29-U2-TEN');

it('F29-U2-TEN-09: context cleared even if handler throws exception', function (): void {
    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    try {
        (new TenantMiddleware)->handle($request, function () {
            expect(TenantContext::bound())->toBeTrue();
            throw new RuntimeException('handler crash');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(TenantContext::bound())->toBeFalse();
})->group('F29-U2-TEN');

it('F29-U2-TEN-10: sequential requests do not leak tenant context', function (): void {
    $tenantA = $this->tenant;
    $tenantB = Tenant::factory()->create();
    $userB = User::factory()->create();
    make_tenant_member($userB, $tenantB, 'owner');
    $userB->forceFill(['current_tenant_id' => $tenantB->id])->save();

    $requestA = Request::create('/api/v1/test', 'GET');
    $requestA->setUserResolver(fn () => $this->user);
    (new TenantMiddleware)->handle($requestA, function () use ($tenantA) {
        expect(TenantContext::id())->toBe($tenantA->id);

        return response('', 200);
    });

    $requestB = Request::create('/api/v1/test', 'GET');
    $requestB->setUserResolver(fn () => $userB);
    (new TenantMiddleware)->handle($requestB, function () use ($tenantB) {
        expect(TenantContext::id())->toBe($tenantB->id);

        return response('', 200);
    });

    expect(TenantContext::bound())->toBeFalse();
})->group('F29-U2-TEN');

it('F29-U2-TEN-11: request after tenant-bound request has no leaked context', function (): void {
    $requestA = Request::create('/api/v1/test', 'GET');
    $requestA->setUserResolver(fn () => $this->user);
    (new TenantMiddleware)->handle($requestA, fn () => response('', 200));

    expect(TenantContext::bound())->toBeFalse();

    $requestB = Request::create('/api/v1/test', 'GET');
    $response = (new TenantMiddleware)->handle($requestB, fn () => response('', 200));

    expect($response->getStatusCode())->toBe(403);
})->group('F29-U2-TEN');

it('F29-U2-TEN-12: disabled membership gets 403 NO_TENANT', function (): void {
    DB::table('tenant_users')
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $this->user->id)
        ->update(['status' => 'disabled']);

    $request = Request::create('/api/v1/test', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $response = (new TenantMiddleware)->handle($request, fn () => response('', 200));

    expect($response->getStatusCode())->toBe(403);
    $json = json_decode($response->getContent(), true);
    expect($json['code'])->toBe('NO_TENANT');
})->group('F29-U2-TEN');
