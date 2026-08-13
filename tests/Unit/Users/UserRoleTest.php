<?php

declare(strict_types=1);

use App\Domain\Users\Enums\UserRole;

test('UserRole expone los cuatro roles esperados', function (): void {
    expect(UserRole::cases())
        ->toHaveCount(4)
        ->and(array_map(fn (UserRole $role) => $role->value, UserRole::cases()))
        ->toBe(['super_admin', 'owner', 'admin', 'agent']);
});

test('solo los roles de tenant están ligados a un tenant', function (): void {
    expect(UserRole::tenantRoles())->toBe([UserRole::Owner, UserRole::Admin, UserRole::Agent]);
});

test('fromString resuelve un rol desde su valor', function (): void {
    expect(UserRole::fromString('admin'))->toBe(UserRole::Admin)
        ->and(UserRole::fromString('owner'))->toBe(UserRole::Owner);
});
