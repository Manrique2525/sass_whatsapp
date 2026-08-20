<?php

declare(strict_types=1);

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Listado de leads del tenant (FASE 19 U2).
 */
final class LeadIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:new,contacted,qualified,won,lost'],
            'source' => ['nullable', 'string', 'in:manual,whatsapp,web,referral,other'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
