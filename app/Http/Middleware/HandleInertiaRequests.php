<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Users\Services\AuthorizationService;
use App\Domain\Tenants\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $tenantOptions = [];
        $role = null;
        $permissions = [];

        if ($user !== null) {
            /** @var array<int, Tenant> $tenants */
            $tenants = $user->tenants()
                ->orderBy('name')
                ->get()
                ->all();

            $currentTenantId = $user->current_tenant_id;

            foreach ($tenants as $tenant) {
                $tenantOptions[] = [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                    'is_current' => $tenant->id === $currentTenantId,
                ];
            }

            if ($currentTenantId !== null && $user->belongsToTenantById($currentTenantId)) {
                $role = $user->roleForTenant($currentTenantId)?->value;

                /** @var Tenant $currentTenant */
                $currentTenant = Tenant::query()->find($currentTenantId);
                $permissions = $this->authorization->permissionsForTenant($user, $currentTenant);
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                'tenants' => $tenantOptions,
                'current_tenant_id' => $user?->current_tenant_id,
                'current_role' => $role,
                'permissions' => $permissions,
                'is_super_admin' => $user?->isSuperAdmin() ?? false,
            ],
            'flash' => [
                'status' => session('status'),
            ],
        ];
    }
}
