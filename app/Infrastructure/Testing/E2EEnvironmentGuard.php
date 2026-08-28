<?php

declare(strict_types=1);

namespace App\Infrastructure\Testing;

use RuntimeException;

/**
 * Guard de seguridad del entorno E2E (FASE 30, ADR-110).
 *
 * Impide ejecutar operaciones destructivas (migrate:fresh, limpieza de Redis,
 * purga de fixtures) sobre un entorno que no sea el suyo. Exige, de forma
 * conjunta:
 *
 *  - `APP_ENV=e2e`              (nunca contra local/production/testing)
 *  - base de datos cuyo nombre termine en `_e2e_test` (nunca `whatsapp_saas`)
 *  - índice de Redis dedicado E2E (nunca el 0/1 de dev ni el 14 de pgsql tests)
 *
 * Si alguna precondición falla se lanza una excepción ANTES de tocar datos.
 */
final class E2EEnvironmentGuard
{
    public const E2E_DB_SUFFIX = '_e2e_test';

    /** Índice lógico de Redis dedicado al entorno E2E (ver docs/testing.md). */
    public const E2E_REDIS_INDEX = 15;

    /**
     * Valida TODAS las precondiciones del entorno E2E. Lanza la primera que
     * falle. Usar en `handle()` de cualquier comando/script destructivo.
     */
    public static function assertSafe(): void
    {
        self::assertAppEnvironment();
        self::assertDatabase();
        self::assertRedis();
    }

    public static function assertAppEnvironment(): void
    {
        if (app()->environment() !== 'e2e') {
            throw new RuntimeException(sprintf(
                'E2E guard: APP_ENV debe ser "e2e", se obtuvo "%s". Operación E2E abortada.',
                app()->environment(),
            ));
        }
    }

    public static function assertDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, self::E2E_DB_SUFFIX)) {
            throw new RuntimeException(sprintf(
                'E2E guard: la BD "%s" no termina en "%s". Operación E2E abortada.',
                $database,
                self::E2E_DB_SUFFIX,
            ));
        }
    }

    /**
     * El índice lógico de Redis del entorno E2E debe ser el dedicado, nunca un
     * índice compartido (0/1 de dev, 14 de pgsql tests).
     */
    public static function assertRedis(): void
    {
        $index = (int) config('database.redis.default.database', config('database.redis.options.database', 0));

        if ($index !== self::E2E_REDIS_INDEX) {
            throw new RuntimeException(sprintf(
                'E2E guard: el índice de Redis para E2E debe ser %d, se obtuvo %d. Operación E2E abortada.',
                self::E2E_REDIS_INDEX,
                $index,
            ));
        }
    }

    public static function isSafe(): bool
    {
        try {
            self::assertSafe();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
