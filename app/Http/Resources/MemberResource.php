<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Users\Models\TenantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantUser
 */
final class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'role' => $this->role->value,
            'status' => $this->status->value,
            'joined_at' => $this->joined_at,
            'invited_at' => $this->invited_at,
        ];
    }
}
