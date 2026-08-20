<?php

declare(strict_types=1);

namespace App\Application\Leads\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Exceptions\LeadDuplicateException;
use App\Domain\Leads\Exceptions\LeadNotFoundException;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\ValueObjects\LeadEmailNormalizer;
use App\Domain\Leads\ValueObjects\LeadPhoneNormalizer;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Casos de uso de administración de Leads (FASE 19 U2).
 *
 * Normalización server-side: phone/email se normalizan vía
 * LeadPhoneNormalizer/LeadEmailNormalizer antes de persistir.
 * Deduplicación a nivel de aplicación (sin UNIQUE en DB).
 */
final class LeadService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly LeadPhoneNormalizer $phoneNormalizer,
        private readonly LeadEmailNormalizer $emailNormalizer,
    ) {}

    /**
     * @param  array{search?: string, status?: string, source?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Lead>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewLeads, $tenant);

        $query = Lead::query()->withoutTenantScope()->where('tenant_id', $tenant->id);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['source']) && $filters['source'] !== '') {
            $query->where('source', $filters['source']);
        }

        return $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function showForUser(User $user, Tenant $tenant, string $leadId): Lead
    {
        $this->authorization->authorize($user, TenantPermission::ViewLeads, $tenant);

        return $this->findForTenant($tenant, $leadId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, Tenant $tenant, array $validated): Lead
    {
        $this->authorization->authorize($user, TenantPermission::ManageLeads, $tenant);

        $data = $this->normalize($validated);
        $this->checkDuplicate($tenant, $data);

        $lead = Lead::query()->create([
            'tenant_id' => $tenant->id,
            'name' => (string) ($data['name'] ?? ''),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'status' => $data['status'] ?? LeadStatus::New,
            'source' => $data['source'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->auditLogger->record(
            action: 'lead.created',
            data: [
                'tenant_id' => $tenant->id,
                'status' => $lead->getAttribute('status'),
                'source' => $lead->source,
            ],
            subjectType: Lead::class,
            subjectId: $lead->id,
        );

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, Tenant $tenant, string $leadId, array $validated): Lead
    {
        $this->authorization->authorize($user, TenantPermission::ManageLeads, $tenant);

        $lead = $this->findForTenant($tenant, $leadId);

        $data = $this->normalize($validated);

        if (isset($data['phone']) || isset($data['email'])) {
            $this->checkDuplicate($tenant, $data, $lead->id);
        }

        if (isset($data['status']) && $data['status'] instanceof LeadStatus) {
            /** @var LeadStatus $currentStatus */
            $currentStatus = $lead->getAttribute('status');
            $newStatus = $data['status'];

            if ($currentStatus !== $newStatus && ! $currentStatus->canTransitionTo($newStatus)) {
                throw new \DomainException(
                    "Transición de estado inválida: {$currentStatus->value} → {$newStatus->value}"
                );
            }
        }

        $changed = array_intersect_key($data, array_flip($lead->getFillable()));

        if ($changed === []) {
            return $lead;
        }

        $lead->fill($changed)->save();

        $this->auditLogger->record(
            action: 'lead.updated',
            data: [
                'tenant_id' => $tenant->id,
                'changed' => array_keys($changed),
            ],
            subjectType: Lead::class,
            subjectId: $lead->id,
        );

        return $lead->fresh();
    }

    public function delete(User $user, Tenant $tenant, string $leadId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageLeads, $tenant);

        $lead = $this->findForTenant($tenant, $leadId);

        $lead->delete();

        $this->auditLogger->record(
            action: 'lead.deleted',
            data: [
                'tenant_id' => $tenant->id,
                'status' => $lead->getAttribute('status'),
                'source' => $lead->source,
            ],
            subjectType: Lead::class,
            subjectId: $lead->id,
        );
    }

    private function findForTenant(Tenant $tenant, string $leadId): Lead
    {
        $lead = Lead::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($leadId)
            ->first();

        if ($lead === null) {
            throw new LeadNotFoundException;
        }

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (isset($data['phone'])) {
            $normalized = $this->phoneNormalizer->normalize((string) $data['phone']);
            $data['phone'] = $normalized === '' ? null : $normalized;
        }

        if (isset($data['email'])) {
            $normalized = $this->emailNormalizer->normalize((string) $data['email']);
            $data['email'] = $normalized === '' ? null : $normalized;
        }

        if (isset($data['status']) && is_string($data['status'])) {
            $data['status'] = LeadStatus::from($data['status']);
        }

        return $data;
    }

    /**
     * Verifica duplicados por phone o email dentro del tenant.
     *
     * @param  array<string, mixed>  $data
     */
    private function checkDuplicate(Tenant $tenant, array $data, ?string $excludeId = null): void
    {
        $query = Lead::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at');

        $hasConflict = false;

        if (isset($data['phone'])) {
            $phoneQuery = (clone $query)->where('phone', $data['phone']);
            if ($excludeId !== null) {
                $phoneQuery->where('id', '!=', $excludeId);
            }
            if ($phoneQuery->exists()) {
                $hasConflict = true;
            }
        }

        if (isset($data['email'])) {
            $emailQuery = (clone $query)->where('email', $data['email']);
            if ($excludeId !== null) {
                $emailQuery->where('id', '!=', $excludeId);
            }
            if ($emailQuery->exists()) {
                $hasConflict = true;
            }
        }

        if ($hasConflict) {
            throw new LeadDuplicateException;
        }
    }
}
