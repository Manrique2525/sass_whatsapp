<?php

declare(strict_types=1);

namespace App\Application\Contacts\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Contacts\Exceptions\TagDuplicateException;
use App\Domain\Contacts\Exceptions\TagNotFoundException;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\Tag;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Writer centralizado de tags (FASE 20 U1) + CRUD de administración (U2).
 *
 * U1: findOrCreateByName, assignToContact, removeFromContact.
 * U2: index, show, create, update, delete.
 *
 * Invariantes:
 * - findOrCreateByName: normaliza (trim), tenant-scoped, idempotente.
 * - assignToContact: idempotente (return false si ya existe).
 * - removeFromContact: idempotente (return false si no existe).
 * - CRUD: authorize + audit + tenant-scoped + duplicate check.
 * - Cross-tenant: fail closed (lanza exception).
 */
final class TagService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
    ) {}

    // ── U1 methods ───────────────────────────────────────────────

    /**
     * Busca un tag por nombre dentro del tenant o lo crea.
     *
     * Normaliza: trim. Si queda vacío, falla.
     * UNIQUE (tenant_id, name) protege contra duplicados.
     * En carrera: UniqueConstraintViolationException → re-consulta.
     */
    public function findOrCreateByName(Tenant $tenant, string $name): Tag
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty after normalization.');
        }

        return TenantContext::withId($tenant->id, function () use ($normalized): Tag {
            $tag = Tag::query()->where('name', $normalized)->first();

            if ($tag !== null) {
                return $tag;
            }

            try {
                return Tag::query()->create(['name' => $normalized]);
            } catch (QueryException $e) {
                $existing = Tag::query()->where('name', $normalized)->first();

                if ($existing !== null) {
                    return $existing;
                }

                throw $e;
            }
        });
    }

    /**
     * Asigna un tag a un contacto.
     *
     * Idempotente: si ya está asignado, NO-OP y return false.
     * Return true si se creó la asignación.
     */
    public function assignToContact(Contact $contact, Tag $tag): bool
    {
        $this->assertSameTenant($contact, $tag);

        $alreadyAssigned = DB::table('contact_tag')
            ->where('contact_id', $contact->id)
            ->where('tag_id', $tag->id)
            ->exists();

        if ($alreadyAssigned) {
            return false;
        }

        $contact->tags()->attach($tag->id);

        return true;
    }

    /**
     * Remueve un tag de un contacto.
     *
     * Idempotente: si no está asignado, NO-OP y return false.
     * Return true si se removió la asignación.
     */
    public function removeFromContact(Contact $contact, Tag $tag): bool
    {
        $this->assertSameTenant($contact, $tag);

        $detached = $contact->tags()->detach($tag->id);

        return $detached > 0;
    }

    // ── U2 CRUD methods ──────────────────────────────────────────

    /**
     * @param  array{search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Tag>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewTags, $tenant);

        $query = Tag::query()->withoutTenantScope()->where('tenant_id', $tenant->id);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where('name', 'like', $term);
        }

        return $query->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function show(User $user, Tenant $tenant, string $tagId): Tag
    {
        $this->authorization->authorize($user, TenantPermission::ViewTags, $tenant);

        return $this->findForTenant($tenant, $tagId);
    }

    /**
     * @param  array{name: string}  $validated
     */
    public function create(User $user, Tenant $tenant, array $validated): Tag
    {
        $this->authorization->authorize($user, TenantPermission::ManageTags, $tenant);

        $name = trim((string) $validated['name']);

        if ($name === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty after normalization.');
        }

        $this->checkDuplicate($tenant, $name);

        try {
            $tag = Tag::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $name,
            ]);
        } catch (UniqueConstraintViolationException|QueryException) {
            throw new TagDuplicateException;
        }

        $this->auditLogger->record(
            action: 'tag.created',
            data: [
                'tenant_id' => $tenant->id,
                'name' => $tag->name,
            ],
            subjectType: Tag::class,
            subjectId: $tag->id,
        );

        return $tag;
    }

    /**
     * @param  array{name: string}  $validated
     */
    public function update(User $user, Tenant $tenant, string $tagId, array $validated): Tag
    {
        $this->authorization->authorize($user, TenantPermission::ManageTags, $tenant);

        $tag = $this->findForTenant($tenant, $tagId);

        $name = trim((string) $validated['name']);

        if ($name === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty after normalization.');
        }

        if ($name !== $tag->name) {
            $this->checkDuplicate($tenant, $name, $tag->id);
        }

        $changed = [];
        if ($name !== $tag->name) {
            $changed['name'] = $name;
        }

        if ($changed === []) {
            return $tag;
        }

        try {
            $tag->fill($changed)->save();
        } catch (UniqueConstraintViolationException|QueryException) {
            throw new TagDuplicateException;
        }

        $this->auditLogger->record(
            action: 'tag.updated',
            data: [
                'tenant_id' => $tenant->id,
                'changed' => array_keys($changed),
            ],
            subjectType: Tag::class,
            subjectId: $tag->id,
        );

        return $tag->fresh();
    }

    public function delete(User $user, Tenant $tenant, string $tagId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageTags, $tenant);

        $tag = $this->findForTenant($tenant, $tagId);

        $tag->delete();

        $this->auditLogger->record(
            action: 'tag.deleted',
            data: [
                'tenant_id' => $tenant->id,
                'name' => $tag->name,
            ],
            subjectType: Tag::class,
            subjectId: $tag->id,
        );
    }

    // ── Private helpers ──────────────────────────────────────────

    private function findForTenant(Tenant $tenant, string $tagId): Tag
    {
        $tag = Tag::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($tagId)
            ->first();

        if ($tag === null) {
            throw new TagNotFoundException;
        }

        return $tag;
    }

    /**
     * Verifica que contact y tag pertenezcan al mismo tenant.
     * Fail closed: si no coinciden, lanza exception.
     */
    private function assertSameTenant(Contact $contact, Tag $tag): void
    {
        if ($contact->tenant_id !== $tag->tenant_id) {
            throw new \RuntimeException(
                'Cross-tenant tag assignment denied: contact '.$contact->id
                .' (tenant '.$contact->tenant_id
                .') vs tag '.$tag->id
                .' (tenant '.$tag->tenant_id.').'
            );
        }
    }

    private function checkDuplicate(Tenant $tenant, string $name, ?string $excludeId = null): void
    {
        $query = Tag::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new TagDuplicateException;
        }
    }
}
