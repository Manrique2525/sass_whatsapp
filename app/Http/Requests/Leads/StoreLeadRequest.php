<?php

declare(strict_types=1);

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creación de lead (FASE 19 U2).
 *
 * phone/email se normalizan server-side (LeadPhoneNormalizer/LeadEmailNormalizer).
 * `tenant_id` viene del TenantContext.
 */
final class StoreLeadRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'max:255', 'email'],
            'status' => ['nullable', 'string', 'in:new,contacted,qualified,won,lost'],
            'source' => ['nullable', 'string', 'in:manual,whatsapp,web,referral,other'],
            'notes' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
