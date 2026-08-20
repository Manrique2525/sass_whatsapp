<?php

declare(strict_types=1);

namespace App\Application\Contacts\Services;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\Tag;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writer centralizado de tags (FASE 20 U1).
 *
 * Único punto de entrada para crear, asignar y remover tags.
 * Garantiza multi-tenancy: tag y contact pertenecen al mismo tenant.
 *
 * Invariantes:
 * - findOrCreateByName: normaliza (trim), tenant-scoped, idempotente.
 * - assignToContact: idempotente (return false si ya existe).
 * - removeFromContact: idempotente (return false si no existe).
 * - Cross-tenant: fail closed (lanza exception).
 */
final class TagService
{
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
}
