<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Users\Models\TenantInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantInvitation
 */
final class MemberInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'invited_by' => $this->invited_by,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
