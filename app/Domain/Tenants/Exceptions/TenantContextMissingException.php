<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Exceptions;

use RuntimeException;

/**
 * Se intentó leer/escribir un modelo tenant sin TenantContext activo.
 *
 * Indica un bug de aislamiento (job sin contexto, seeder, CLI, servicio). La
 * ejecución debe detenerse: nunca se debe fabricar un tenant_id a la ligera.
 */
final class TenantContextMissingException extends RuntimeException {}
