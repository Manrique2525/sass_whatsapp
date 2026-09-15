<?php

declare(strict_types=1);

use App\Domain\Billing\Models\Plan;
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

test('la landing expone el catálogo público activo sin datos internos', function (): void {
    $this->seed(PlanSeeder::class);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->component('Landing')
        ->where('plans.0.name', 'Free')
        ->where('plans.0.slug', 'free')
        ->where('plans.0.priceMonthly', '0.00')
        ->where('plans.0.limits.messages', 100)
        ->where('plans.0.limits.contacts', 50)
        ->where('plans.0.limits.flowExecutions', 10)
        ->where('plans.0.limits.users', 3)
        ->where('plans.0.limits.knowledgeDocuments', 2)
        ->where('plans.0.aiIncluded', false)
        ->missing('plans.0.id')
        ->missing('plans.0.stripe_price_id_monthly')
        ->missing('plans.0.features'));
});

test('la landing ordena planes activos y omite planes inactivos', function (): void {
    $this->seed(PlanSeeder::class);
    Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'sort_order' => 1, 'price_monthly' => 29]);
    Plan::factory()->inactive()->create(['slug' => 'legacy', 'name' => 'Legacy', 'sort_order' => 2]);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->component('Landing')
        ->count('plans', 2)
        ->where('plans.1.slug', 'pro')
        ->missing('plans.2'));
});

test('las páginas legales son públicas', function (): void {
    $this->get('/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Legal/Privacy'));

    $this->get('/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Legal/Terms'));
});

test('el sitemap y robots sólo publican rutas públicas', function (): void {
    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.url('/').'</loc>', false)
        ->assertSee('<loc>'.url('/privacy').'</loc>', false)
        ->assertSee('<loc>'.url('/terms').'</loc>', false)
        ->assertDontSee('/dashboard');

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Allow: /')
        ->assertSee('Sitemap: '.url('/sitemap.xml'))
        ->assertSee('Disallow: /dashboard');
});
