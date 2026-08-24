<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a Checkout Session (FASE 24 U2).
 *
 * Accepts plan UUID and interval. Backend resolves everything else.
 * Rejects tenant_id, price_id, amount, currency, status, etc.
 */
final class StoreCheckoutRequest extends FormRequest
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
            'interval' => ['required', 'string', 'in:monthly,yearly'],
        ];
    }
}
