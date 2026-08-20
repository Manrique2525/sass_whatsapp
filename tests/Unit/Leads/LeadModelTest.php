<?php

declare(strict_types=1);

use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenants\Models\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function (): void {
    TenantContext::clear();
});

/*
|--------------------------------------------------------------------------
| Lead Model Tests (SQLite)
|--------------------------------------------------------------------------
|
| LEAD-DB-01..17 — Model, relationships, enums, factories, constraints.
| Corren en SQLite :memory: (phpunit.xml default).
| No validan partial unique indexes (ver tests/Postgres/Lead/).
|
*/

it('LEAD-DB-01: factory creates lead', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->create();

    expect($lead->id)->toBeString()->not->toBeEmpty();
    expect($lead->name)->toBeString()->not->toBeEmpty();
    expect($lead->created_at)->not->toBeNull();
    expect($lead->updated_at)->not->toBeNull();
})->group('LEAD-DB-01');

it('LEAD-DB-02: UUID primary key', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->create();

    expect($lead->id)->toBeUUID();
})->group('LEAD-DB-02');

it('LEAD-DB-03: tenant assigned correctly', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->create();

    expect($lead->tenant_id)->toBe($tenant->id);
    expect($lead->tenant)->toBeInstanceOf(Tenant::class);
    expect($lead->tenant->id)->toBe($tenant->id);
})->group('LEAD-DB-03');

it('LEAD-DB-04: status cast', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->create(['status' => LeadStatus::Contacted]);

    expect($lead->status)->toBeInstanceOf(LeadStatus::class);
    expect($lead->status)->toBe(LeadStatus::Contacted);
    expect($lead->status->value)->toBe('contacted');
})->group('LEAD-DB-04');

it('LEAD-DB-05: default status is new', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->create();

    expect($lead->status)->toBe(LeadStatus::New);
    expect($lead->status->value)->toBe('new');
})->group('LEAD-DB-05');

it('LEAD-DB-06: soft delete', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->create();
    $leadId = $lead->id;

    $lead->delete();

    expect(Lead::find($leadId))->toBeNull();
    expect(Lead::withTrashed()->find($leadId))->not->toBeNull();
    expect(Lead::withTrashed()->find($leadId)->deleted_at)->not->toBeNull();
})->group('LEAD-DB-06');

it('LEAD-DB-07: new factory state', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->asNew()->create();

    expect($lead->status)->toBe(LeadStatus::New);
})->group('LEAD-DB-07');

it('LEAD-DB-08: contacted factory state', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->contacted()->create();

    expect($lead->status)->toBe(LeadStatus::Contacted);
})->group('LEAD-DB-08');

it('LEAD-DB-09: qualified factory state', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->qualified()->create();

    expect($lead->status)->toBe(LeadStatus::Qualified);
})->group('LEAD-DB-09');

it('LEAD-DB-10: won factory state', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->won()->create();

    expect($lead->status)->toBe(LeadStatus::Won);
})->group('LEAD-DB-10');

it('LEAD-DB-11: lost factory state', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->lost()->create();

    expect($lead->status)->toBe(LeadStatus::Lost);
})->group('LEAD-DB-11');

it('LEAD-DB-12: nullable phone', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->withoutPhone()->create();

    expect($lead->phone)->toBeNull();
})->group('LEAD-DB-12');

it('LEAD-DB-13: nullable email', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->withoutEmail()->create();

    expect($lead->email)->toBeNull();
})->group('LEAD-DB-13');

it('LEAD-DB-14: nullable source', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);

    $lead = Lead::factory()->withoutSource()->create();

    expect($lead->source)->toBeNull();
})->group('LEAD-DB-14');

it('LEAD-DB-15: tenant_id not in fillable', function (): void {
    $lead = new Lead;

    expect($lead->getFillable())->not->toContain('tenant_id');
})->group('LEAD-DB-15');

it('LEAD-DB-16: model is final', function (): void {
    $reflection = new ReflectionClass(Lead::class);

    expect($reflection->isFinal())->toBeTrue();
})->group('LEAD-DB-16');

it('LEAD-DB-17: leads table has expected columns', function (): void {
    $tenant = Tenant::factory()->create();
    TenantContext::setId($tenant->id);
    Lead::factory()->create();

    $columns = Schema::getColumns('leads');
    $columnNames = array_column($columns, 'name');

    expect($columnNames)->toContain('id');
    expect($columnNames)->toContain('tenant_id');
    expect($columnNames)->toContain('name');
    expect($columnNames)->toContain('phone');
    expect($columnNames)->toContain('email');
    expect($columnNames)->toContain('status');
    expect($columnNames)->toContain('source');
    expect($columnNames)->toContain('notes');
    expect($columnNames)->toContain('created_at');
    expect($columnNames)->toContain('updated_at');
    expect($columnNames)->toContain('deleted_at');
})->group('LEAD-DB-17');
