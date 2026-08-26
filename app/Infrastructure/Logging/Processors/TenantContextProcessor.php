<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging\Processors;

use App\Infrastructure\Tenancy\TenantContext;
use Monolog\LogRecord;

/**
 * Monolog processor que inyecta tenant_id a cada log line cuando
 * TenantContext está activo.
 *
 * Seguro para workers de larga duración: resuelve el tenant en el momento
 * del log (no cachea globalmente).
 */
final class TenantContextProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $tenantId = TenantContext::id();

        if ($tenantId !== null) {
            $record->extra['tenant_id'] = $tenantId;
        }

        return $record;
    }
}
