<?php

declare(strict_types=1);

use App\Domain\Leads\Enums\LeadStatus;

/*
|--------------------------------------------------------------------------
| Lead Status Transition Tests (FASE 19 U2)
|--------------------------------------------------------------------------
|
| LEAD-TRANS-01..12 — Validación de transiciones de estado.
| Tests puros, sin DB.
|
*/

it('LEAD-TRANS-01: new can transition to contacted', function (): void {
    expect(LeadStatus::New->canTransitionTo(LeadStatus::Contacted))->toBeTrue();
})->group('LEAD-TRANS-01');

it('LEAD-TRANS-02: new cannot transition to qualified', function (): void {
    expect(LeadStatus::New->canTransitionTo(LeadStatus::Qualified))->toBeFalse();
})->group('LEAD-TRANS-02');

it('LEAD-TRANS-03: new cannot transition to won', function (): void {
    expect(LeadStatus::New->canTransitionTo(LeadStatus::Won))->toBeFalse();
})->group('LEAD-TRANS-03');

it('LEAD-TRANS-04: new cannot transition to lost', function (): void {
    expect(LeadStatus::New->canTransitionTo(LeadStatus::Lost))->toBeFalse();
})->group('LEAD-TRANS-04');

it('LEAD-TRANS-05: contacted can transition to qualified', function (): void {
    expect(LeadStatus::Contacted->canTransitionTo(LeadStatus::Qualified))->toBeTrue();
})->group('LEAD-TRANS-05');

it('LEAD-TRANS-06: contacted can transition to won', function (): void {
    expect(LeadStatus::Contacted->canTransitionTo(LeadStatus::Won))->toBeTrue();
})->group('LEAD-TRANS-06');

it('LEAD-TRANS-07: contacted can transition to lost', function (): void {
    expect(LeadStatus::Contacted->canTransitionTo(LeadStatus::Lost))->toBeTrue();
})->group('LEAD-TRANS-07');

it('LEAD-TRANS-08: qualified can transition to won', function (): void {
    expect(LeadStatus::Qualified->canTransitionTo(LeadStatus::Won))->toBeTrue();
})->group('LEAD-TRANS-08');

it('LEAD-TRANS-09: qualified can transition to lost', function (): void {
    expect(LeadStatus::Qualified->canTransitionTo(LeadStatus::Lost))->toBeTrue();
})->group('LEAD-TRANS-09');

it('LEAD-TRANS-10: won is terminal', function (): void {
    expect(LeadStatus::Won->canTransitionTo(LeadStatus::New))->toBeFalse();
    expect(LeadStatus::Won->canTransitionTo(LeadStatus::Contacted))->toBeFalse();
    expect(LeadStatus::Won->canTransitionTo(LeadStatus::Qualified))->toBeFalse();
    expect(LeadStatus::Won->canTransitionTo(LeadStatus::Lost))->toBeFalse();
})->group('LEAD-TRANS-10');

it('LEAD-TRANS-11: lost can transition to new (reopen)', function (): void {
    expect(LeadStatus::Lost->canTransitionTo(LeadStatus::New))->toBeTrue();
})->group('LEAD-TRANS-11');

it('LEAD-TRANS-12: lost cannot transition to other states', function (): void {
    expect(LeadStatus::Lost->canTransitionTo(LeadStatus::Contacted))->toBeFalse();
    expect(LeadStatus::Lost->canTransitionTo(LeadStatus::Qualified))->toBeFalse();
    expect(LeadStatus::Lost->canTransitionTo(LeadStatus::Won))->toBeFalse();
})->group('LEAD-TRANS-12');

it('LEAD-TRANS-13: same state transition rejected', function (): void {
    expect(LeadStatus::New->canTransitionTo(LeadStatus::New))->toBeFalse();
    expect(LeadStatus::Contacted->canTransitionTo(LeadStatus::Contacted))->toBeFalse();
})->group('LEAD-TRANS-13');
