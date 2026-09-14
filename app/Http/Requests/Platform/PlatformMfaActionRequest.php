<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

final class PlatformMfaActionRequest extends PlatformMfaRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => $this->passwordRules(), 'factor' => ['required', 'string', 'max:64']];
    }
}
