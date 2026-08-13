<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use App\Domain\Users\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de invitación a un tenant. La regla de rol asignable por el actor
 * la aplica `InvitationService` (backend siempre manda).
 */
final class StoreMemberInvitationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assignable = implode(',', array_map(
            static fn (UserRole $role): string => $role->value,
            UserRole::assignableTenantRoles(),
        ));

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', "in:{$assignable}"],
        ];
    }
}
