<?php

declare(strict_types=1);

namespace App\Application\Faq\Services;

use App\Application\Audit\Services\AuditLogger;
use App\Application\Users\Services\AuthorizationService;
use App\Domain\Faq\Enums\FaqStatus;
use App\Domain\Faq\Exceptions\FaqDuplicateException;
use App\Domain\Faq\Exceptions\FaqInvalidQuestionException;
use App\Domain\Faq\Exceptions\FaqNotFoundException;
use App\Domain\Faq\Models\Faq;
use App\Domain\Faq\ValueObjects\FaqQuestionNormalizer;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Enums\TenantPermission;
use App\Domain\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Casos de uso de administración de FAQs (FASE 18 U3).
 *
 * Normalización server-side: `question` se normaliza vía `FaqQuestionNormalizer`
 * antes de persistir. El frontend jamás envía `normalized_question`.
 */
final class FaqService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditLogger $auditLogger,
        private readonly FaqQuestionNormalizer $normalizer,
    ) {}

    /**
     * @param  array{search?: string, status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Faq>
     */
    public function index(User $user, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($user, TenantPermission::ViewFaqs, $tenant);

        $query = Faq::query()->withoutTenantScope()->where('tenant_id', $tenant->id);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where('question', 'like', $term);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function showForUser(User $user, Tenant $tenant, string $faqId): Faq
    {
        $this->authorization->authorize($user, TenantPermission::ViewFaqs, $tenant);

        return $this->findForTenant($tenant, $faqId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, Tenant $tenant, array $validated): Faq
    {
        $this->authorization->authorize($user, TenantPermission::ManageFaqs, $tenant);

        $normalized = $this->normalizer->normalize((string) $validated['question']);

        if ($normalized === '') {
            throw new FaqInvalidQuestionException;
        }

        try {
            $faq = Faq::query()->create([
                'question' => (string) $validated['question'],
                'normalized_question' => $normalized,
                'answer' => (string) $validated['answer'],
                'status' => $validated['status'] ?? FaqStatus::Active,
                'priority' => $validated['priority'] ?? 0,
            ]);
        } catch (UniqueConstraintViolationException|QueryException) {
            throw new FaqDuplicateException;
        }

        $this->auditLogger->record(
            action: 'faq.created',
            data: [
                'tenant_id' => $tenant->id,
                'status' => $faq->getAttribute('status'),
                'priority' => $faq->priority,
                'question_length' => mb_strlen($faq->question, 'UTF-8'),
            ],
            subjectType: Faq::class,
            subjectId: $faq->id,
        );

        return $faq;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(User $user, Tenant $tenant, string $faqId, array $validated): Faq
    {
        $this->authorization->authorize($user, TenantPermission::ManageFaqs, $tenant);

        $faq = $this->findForTenant($tenant, $faqId);

        $data = $validated;

        if (isset($data['question']) && $data['question'] !== '') {
            $normalized = $this->normalizer->normalize((string) $data['question']);

            if ($normalized === '') {
                throw new FaqInvalidQuestionException;
            }

            $data['question'] = (string) $data['question'];
            $data['normalized_question'] = $normalized;
        }

        if (isset($data['answer']) && $data['answer'] !== '') {
            $data['answer'] = (string) $data['answer'];
        }

        $changed = array_intersect_key($data, array_flip($faq->getFillable()));

        if ($changed === []) {
            return $faq;
        }

        try {
            $faq->fill($changed)->save();
        } catch (UniqueConstraintViolationException|QueryException) {
            throw new FaqDuplicateException;
        }

        $this->auditLogger->record(
            action: 'faq.updated',
            data: [
                'tenant_id' => $tenant->id,
                'changed' => array_keys($changed),
            ],
            subjectType: Faq::class,
            subjectId: $faq->id,
        );

        return $faq->fresh();
    }

    public function delete(User $user, Tenant $tenant, string $faqId): void
    {
        $this->authorization->authorize($user, TenantPermission::ManageFaqs, $tenant);

        $faq = $this->findForTenant($tenant, $faqId);

        $faq->delete();

        $this->auditLogger->record(
            action: 'faq.deleted',
            data: [
                'tenant_id' => $tenant->id,
                'status' => $faq->getAttribute('status'),
                'priority' => $faq->priority,
            ],
            subjectType: Faq::class,
            subjectId: $faq->id,
        );
    }

    private function findForTenant(Tenant $tenant, string $faqId): Faq
    {
        $faq = Faq::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKey($faqId)
            ->first();

        if ($faq === null) {
            throw new FaqNotFoundException;
        }

        return $faq;
    }
}
