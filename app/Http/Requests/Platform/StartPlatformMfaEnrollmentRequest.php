<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

final class StartPlatformMfaEnrollmentRequest extends PlatformMfaRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => $this->passwordRules()];
    }
}
