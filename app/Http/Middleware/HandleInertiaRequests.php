<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenants\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $tenantOptions = [];

        if ($user !== null) {
            /** @var array<int, Tenant> $tenants */
            $tenants = $user->tenants()
                ->orderBy('name')
                ->get()
                ->all();

            foreach ($tenants as $tenant) {
                $tenantOptions[] = [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status->value,
                    'is_current' => $tenant->id === $user->current_tenant_id,
                ];
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
            ],
            'flash' => [
                'status' => session('status'),
            ],
        ];
    }
}
