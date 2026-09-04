<?php

declare(strict_types=1);

use App\Domain\Users\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('la landing también está disponible para usuarios autenticados', function (): void {
    $this->seed(PlanSeeder::class);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Landing'));
});

test('la landing expone sólo los límites públicos del plan Free real', function (): void {
    $this->seed(PlanSeeder::class);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->component('Landing')
        ->where('freePlan.name', 'Free')
        ->where('freePlan.slug', 'free')
        ->where('freePlan.limits.messages', 100)
        ->where('freePlan.limits.contacts', 50)
        ->where('freePlan.limits.flowExecutions', 10)
        ->where('freePlan.limits.users', 3)
        ->where('freePlan.limits.knowledgeDocuments', 2)
        ->where('freePlan.aiIncluded', false)
        ->missing('freePlan.id')
        ->missing('freePlan.priceMonthly'));
});

test('las páginas legales son públicas', function (): void {
    $this->get('/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Legal/Privacy'));

    $this->get('/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Legal/Terms'));
});
