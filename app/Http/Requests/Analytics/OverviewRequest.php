<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validación del endpoint GET /analytics/overview (FASE 21 U3).
 *
 * from y to son opcionales (defaults manejados por AnalyticsService).
 * Solo se valida formato, rango y longitud máxima.
 */
final class OverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.date_format' => 'El campo from debe tener formato YYYY-MM-DD.',
            'to.date_format' => 'El campo to debe tener formato YYYY-MM-DD.',
        ];
    }

    /**
     * Validate after date rules: from <= to, range <= 365 days.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if ($from === null || $to === null) {
                return;
            }

            if (strcmp($from, $to) > 0) {
                $validator->errors()->add(
                    'from',
                    'El campo from no puede ser posterior a to.',
                );
            }

            $fromDate = Carbon::parse($from);
            $toDate = Carbon::parse($to);
            $days = (int) $fromDate->diffInDays($toDate) + 1;

            if ($days > 365) {
                $validator->errors()->add(
                    'to',
                    'El rango no puede exceder 365 días.',
                );
            }
        });
    }
}
