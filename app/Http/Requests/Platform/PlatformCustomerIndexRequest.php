<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Tenants\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlatformCustomerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'plan_id' => ['nullable', 'uuid'],
            'subscription_status' => ['nullable', Rule::in(array_column(SubscriptionStatus::cases(), 'value'))],
            'status' => ['nullable', Rule::in(array_column(TenantStatus::cases(), 'value'))],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'sort' => ['nullable', Rule::in(['name', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
