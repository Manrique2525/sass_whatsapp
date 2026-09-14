<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

final class PlatformMfaChallengeRequest extends PlatformMfaRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:64']];
    }
}
