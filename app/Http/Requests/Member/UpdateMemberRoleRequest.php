<?php

declare(strict_types=1);

namespace App\Http\Requests\Member;

use App\Domain\Users\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de cambio de rol de un miembro. Las reglas de negocio (último
 * owner, admin vs owner, etc.) las aplica `MemberService` en backend.
 */
final class UpdateMemberRoleRequest extends FormRequest
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
            'role' => ['required', 'string', "in:{$assignable}"],
        ];
    }
}
