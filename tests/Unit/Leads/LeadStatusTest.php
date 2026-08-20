<?php

declare(strict_types=1);

use App\Domain\Leads\Enums\LeadStatus;

/*
|--------------------------------------------------------------------------
| LeadStatus Enum Tests (FASE 19 U1)
|--------------------------------------------------------------------------
|
| LEAD-STATUS-01..06 — Enum values, labels, case count.
| Corren en SQLite :memory: (phpunit.xml default).
|
*/

it('LEAD-STATUS-01: enum has five cases', function (): void {
    expect(LeadStatus::cases())->toHaveCount(5);
})->group('LEAD-STATUS-01');

it('LEAD-STATUS-02: case values are correct', function (): void {
    expect(LeadStatus::New->value)->toBe('new');
    expect(LeadStatus::Contacted->value)->toBe('contacted');
    expect(LeadStatus::Qualified->value)->toBe('qualified');
    expect(LeadStatus::Won->value)->toBe('won');
    expect(LeadStatus::Lost->value)->toBe('lost');
})->group('LEAD-STATUS-02');

it('LEAD-STATUS-03: label returns correct values', function (): void {
    expect(LeadStatus::New->label())->toBe('New');
    expect(LeadStatus::Contacted->label())->toBe('Contacted');
    expect(LeadStatus::Qualified->label())->toBe('Qualified');
    expect(LeadStatus::Won->label())->toBe('Won');
    expect(LeadStatus::Lost->label())->toBe('Lost');
})->group('LEAD-STATUS-03');

it('LEAD-STATUS-04: from value creates enum', function (): void {
    expect(LeadStatus::from('new'))->toBe(LeadStatus::New);
    expect(LeadStatus::from('contacted'))->toBe(LeadStatus::Contacted);
    expect(LeadStatus::from('qualified'))->toBe(LeadStatus::Qualified);
    expect(LeadStatus::from('won'))->toBe(LeadStatus::Won);
    expect(LeadStatus::from('lost'))->toBe(LeadStatus::Lost);
})->group('LEAD-STATUS-04');

it('LEAD-STATUS-05: tryFrom returns null for invalid value', function (): void {
    expect(LeadStatus::tryFrom('invalid'))->toBeNull();
    expect(LeadStatus::tryFrom(''))->toBeNull();
})->group('LEAD-STATUS-05');

it('LEAD-STATUS-06: string-backed enum serializes correctly', function (): void {
    $status = LeadStatus::New;

    expect($status->value)->toBe('new');
    expect($status->label())->toBe('New');
    expect(LeadStatus::from('new'))->toBe(LeadStatus::New);
})->group('LEAD-STATUS-06');
