<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Billing\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlatformSubscriptionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => trim((string) $this->input('search', '')),
            'provider' => trim((string) $this->input('provider', '')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'tenant_id' => ['nullable', 'uuid'],
            'plan_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'provider' => ['nullable', Rule::in(['local', 'stripe'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
