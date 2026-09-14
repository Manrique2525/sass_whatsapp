<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Billing\Enums\UsageCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class PlatformPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $categories = array_map(static fn (UsageCategory $category): string => $category->value, UsageCategory::cases());

        return [
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('plans', 'slug')->ignore($this->route('plan'))],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'price_monthly' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'stripe_price_id_monthly' => ['nullable', 'string', 'max:255', 'regex:/^price_[A-Za-z0-9_-]+$/'],
            'stripe_price_id_yearly' => ['nullable', 'string', 'max:255', 'regex:/^price_[A-Za-z0-9_-]+$/'],
            'limits' => ['required', 'array:'.implode(',', $categories)],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['required', 'array:ai_enabled'],
            'features.ai_enabled' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
