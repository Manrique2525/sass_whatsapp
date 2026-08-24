<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the plan for an existing subscription (FASE 23 U3).
 *
 * Accepts a plan UUID. Backend resolves everything else.
 * Only plan_id is mutable — status, period, tenant are server-controlled.
 */
final class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'uuid'],
        ];
    }
}
