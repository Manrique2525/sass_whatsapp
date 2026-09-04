<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('la landing también está disponible para usuarios autenticados', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Landing'));
});
