<?php

declare(strict_types=1);

namespace App\Application\Contacts\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Contacts\Exceptions\ContactDuplicateException;
use App\Domain\Contacts\Exceptions\ContactNotFoundException;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Casos de uso del CRM de contactos (FASE 7, ADR-030).
 *
 * Invariantes:
 * - `phone` se normaliza SIEMPRE a E.164 canónico con `+` inicial y sin
 *   separadores (`normalizePhone`). El índice único parcial (activos) protege
 *   la unicidad por tenant; `assertPhoneUnique` da el error de negocio y el
 *   índice es el backstop final contra carreras.
 * - El contacto se resuelve SIN el scope global (`withoutTenantScope`) pero
 *   filtrando SIEMPRE por `tenant_id` del tenant autorizado: el 404 oculta la
 *   existencia cross-tenant (ADR-010/023).
 * - `tenant_id` nunca viene del frontend: lo fija `BelongsToTenant` con el
 *   TenantContext activo.
 */
final class ContactService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Normaliza un teléfono a E.164 canónico: `+` inicial + solo dígitos.
     *
     * `'+54 11 5555 4444'` y `'5491155554444'` normalizan al mismo valor, que
     * es el que Meta reporta como `wa_id` (FASE 9).
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        return $digits === '' ? '' : '+'.$digits;
    }

    /**
     * @param  array{search?: string, phone?: string, email?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Contact>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewContacts, $tenant);

        $query = Contact::query();

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (isset($filters['phone']) && $filters['phone'] !== '') {
            $query->where('phone', 'like', self::normalizePhone($filters['phone']).'%');
        }

        if (isset($filters['email']) && $filters['email'] !== '') {
            $query->where('email', 'like', '%'.$filters['email'].'%');
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    public function showForUser(User $user, Tenant $tenant, string $contactId): Contact
    {
        $this->authorization->authorize($user, TenantPermission::ViewContacts, $tenant);

        return $this->findForTenant($tenant, $contactId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, Tenant $tenant, array $validated): Contact
    {
        $this->authorization->authorize($user, TenantPermission::ManageContacts, $tenant);

        $phone = self::normalizePhone((string) $validated['phone']);
        $this->assertPhoneUnique($tenant, $phone, null);

        $contact = Contact::query()->create([
            ...$validated,
            'phone' => $phone,
            'name' => (string) $validated['name'],
        ]);

        $this->auditLogger->record(
            action: 'contact.created',
            data: ['tenant_id' => $tenant->id],
            subjectType: Contact::class,
            subjectId: $contact->id,
        );

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, Tenant $tenant, string $contactId, array $validated): Contact
    {
        $this->authorization->authorize($user, TenantPermission::ManageContacts, $tenant);

        $contact = $this->findForTenant($tenant, $contactId);

        $data = $validated;

        if (isset($data['phone']) && $data['phone'] !== '') {
            $data['phone'] = self::normalizePhone((string) $data['phone']);
            $this->assertPhoneUnique($tenant, $data['phone'], $contact->id);
        }

        $changed = array_intersect_key($data, array_flip($contact->getFillable()));

        if ($changed === []) {
            return $contact;
        }

        $contact->fill($changed)->save();

        $this->auditLogger->record(
            action: 'contact.updated',
            data: [
                'tenant_id' => $tenant->id,
                'changed' => array_keys($changed),
            ],
            subjectType: Contact::class,
            subjectId: $contact->id,
        );

        return $contact->fresh();
    }

    public function delete(User $user, Tenant $tenant, string $contactId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageContacts, $tenant);

        $contact = $this->findForTenant($tenant, $contactId);

        $contact->delete();

        $this->auditLogger->record(
            action: 'contact.deleted',
            data: ['tenant_id' => $tenant->id, 'phone' => $contact->phone],
            subjectType: Contact::class,
            subjectId: $contact->id,
        );
    }

    /**
     * Devuelve el contacto del tenant para un número o lo crea (FASE 9).
     *
     * SIN autorización de usuario: lo invocan jobs del webhook de WhatsApp.
     * Busca fuera del scope y setea TenantContext en el create (los jobs son
     * tenant-aware). Si el índice único detecta una carrera (dos eventos
     * simultáneos), re-consulta; si sigue sin existir, relanza la excepción.
     */
    public function findOrCreateForPhone(Tenant $tenant, string $phone): Contact
    {
        $normalized = self::normalizePhone($phone);

        $contact = $this->findByPhone($tenant, $normalized);

        if ($contact !== null) {
            return $contact;
        }

        TenantContext::setId($tenant->id);

        try {
            $contact = Contact::query()->create([
                'name' => $normalized === '' ? 'Desconocido' : $normalized,
                'phone' => $normalized,
            ]);
        } catch (QueryException $e) {
            $existing = $this->findByPhone($tenant, $normalized);

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        } finally {
            TenantContext::clear();
        }

        return $contact;
    }

    private function assertPhoneUnique(Tenant $tenant, string $phone, ?string $excludeId): void
    {
        $exists = Contact::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $phone)
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->exists();

        if ($exists) {
            throw new ContactDuplicateException($phone);
        }
    }

    private function findByPhone(Tenant $tenant, string $phone): ?Contact
    {
        return Contact::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $phone)
            ->first();
    }

    private function findForTenant(Tenant $tenant, string $contactId): Contact
    {
        $contact = Contact::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($contactId)
            ->first();

        if ($contact === null) {
            throw new ContactNotFoundException;
        }

        return $contact;
    }
}
