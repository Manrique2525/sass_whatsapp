<?php

declare(strict_types=1);

namespace App\Application\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Registro de auditoría. Se usa desde servicios de aplicación (no desde
 * controladores) para acciones sensibles como switch de tenant o actualizaciones.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function record(
        string $action,
        ?array $data = null,
        string|int|null $subjectType = null,
        string|int|null $subjectId = null,
        ?int $actorUserId = null,
        ?string $tenantId = null,
    ): AuditLog {
        $audit = new AuditLog;

        $audit->actor_user_id = $actorUserId ?? Auth::id();
        $audit->tenant_id = $tenantId ?? TenantContext::id();
        $audit->action = $action;
        $audit->subject_type = $subjectType;
        $audit->subject_id = $subjectId;
        $audit->data = $data;
        $audit->ip_address = request()->ip();
        $audit->user_agent = Str::limit((string) request()->userAgent(), 500);

        $audit->save();

        return $audit;
    }
}
