<?php

declare(strict_types=1);

use App\Infrastructure\Testing\E2EEnvironmentGuard;

/**
 * Guard del entorno E2E (FASE 30, ADR-110).
 *
 * E2E-ENV-01: APP_ENV distinto de "e2e" aborta.
 * E2E-ENV-02: BD que no termina en "_e2e_test" aborta.
 * E2E-ENV-02b: índice de Redis no dedicado (15) aborta.
 * E2E-ENV-03: entorno correcto pasa todas las precondiciones.
 */
function e2eGuardDatabaseSuffix(): string
{
    return E2EEnvironmentGuard::E2E_DB_SUFFIX;
}

test('E2E-ENV-01: refuse si APP_ENV no es "e2e"', function (): void {
    app()->instance('env', 'testing');
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql.database', 'whatsapp_saas_e2e_test');

    expect(fn () => E2EEnvironmentGuard::assertAppEnvironment())
        ->toThrow(RuntimeException::class, 'APP_ENV debe ser "e2e"');
});

test('E2E-ENV-01b: isSafe() devuelve false si APP_ENV no es "e2e"', function (): void {
    app()->instance('env', 'local');

    expect(E2EEnvironmentGuard::isSafe())->toBeFalse();
});

test('E2E-ENV-02: refuse si la BD no termina en ".e2e_test"', function (): void {
    app()->instance('env', 'e2e');
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql.database', 'whatsapp_saas');

    expect(fn () => E2EEnvironmentGuard::assertDatabase())
        ->toThrow(RuntimeException::class, 'no termina en "'.e2eGuardDatabaseSuffix().'"');
});

test('E2E-ENV-02b: refuse si el índice de Redis no es el dedicado', function (): void {
    app()->instance('env', 'e2e');
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql.database', 'whatsapp_saas_e2e_test');
    config()->set('database.redis.default.database', 1);

    expect(fn () => E2EEnvironmentGuard::assertRedis())
        ->toThrow(RuntimeException::class, 'índice de Redis');
});

test('E2E-ENV-03: pasa cuando APP_ENV, BD y Redis son los correctos', function (): void {
    app()->instance('env', 'e2e');
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql.database', 'whatsapp_saas_e2e_test');
    config()->set('database.redis.default.database', E2EEnvironmentGuard::E2E_REDIS_INDEX);

    expect(E2EEnvironmentGuard::isSafe())->toBeTrue();
    expect(fn () => E2EEnvironmentGuard::assertSafe())->not->toThrow(RuntimeException::class);
});
