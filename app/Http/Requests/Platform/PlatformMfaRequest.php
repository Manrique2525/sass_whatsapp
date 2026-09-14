<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

abstract class PlatformMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string> */
    protected function passwordRules(): array
    {
        return ['required', 'string', 'current_password:web'];
    }
}
