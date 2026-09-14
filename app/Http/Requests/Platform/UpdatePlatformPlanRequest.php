<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Domain\Billing\Models\Plan;

final class UpdatePlatformPlanRequest extends PlatformPlanRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $plan = $this->route('plan');

        if ($plan instanceof Plan && $this->changesSensitiveFields($plan)) {
            $rules['current_password'] = ['required', 'current_password:web'];
        }

        return $rules;
    }

    private function changesSensitiveFields(Plan $plan): bool
    {
        return (string) $this->input('price_monthly') !== (string) $plan->price_monthly
            || (string) $this->input('price_yearly') !== (string) $plan->price_yearly
            || $this->input('stripe_price_id_monthly') !== $plan->stripe_price_id_monthly
            || $this->input('stripe_price_id_yearly') !== $plan->stripe_price_id_yearly
            || (bool) $this->boolean('is_active') !== (bool) $plan->is_active
            || $this->input('limits', []) !== ($plan->limits ?? [])
            || $this->input('features', []) !== ($plan->features ?? []);
    }
}
