<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\PlanInterval;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Enums\UsageCategory;

/*
|--------------------------------------------------------------------------
| Billing Enum Tests (FASE 23 U1)
|--------------------------------------------------------------------------
|
| BILL-ENUM-01..09 — Domain invariants for billing enums.
| Corren en SQLite :memory:.
|
*/

it('BILL-ENUM-01: SubscriptionStatus has exact expected cases', function (): void {
    $cases = array_column(SubscriptionStatus::cases(), 'value');

    expect($cases)->toHaveCount(3)
        ->and($cases)->toContain('active')
        ->and($cases)->toContain('pending')
        ->and($cases)->toContain('cancelled');
})->group('BILL-ENUM-01');

it('BILL-ENUM-02: PlanInterval has exact expected cases', function (): void {
    $cases = array_column(PlanInterval::cases(), 'value');

    expect($cases)->toHaveCount(2)
        ->and($cases)->toContain('monthly')
        ->and($cases)->toContain('yearly');
})->group('BILL-ENUM-02');

it('BILL-ENUM-03: UsageCategory has exact expected cases', function (): void {
    $cases = array_column(UsageCategory::cases(), 'value');

    expect($cases)->toHaveCount(6)
        ->and($cases)->toContain('messages')
        ->and($cases)->toContain('ai_tokens')
        ->and($cases)->toContain('contacts')
        ->and($cases)->toContain('flow_executions')
        ->and($cases)->toContain('users')
        ->and($cases)->toContain('knowledge_documents');
})->group('BILL-ENUM-03');

it('BILL-ENUM-04: SubscriptionStatus values are stable strings', function (): void {
    expect(SubscriptionStatus::Active->value)->toBe('active')
        ->and(SubscriptionStatus::Pending->value)->toBe('pending')
        ->and(SubscriptionStatus::Cancelled->value)->toBe('cancelled');
})->group('BILL-ENUM-04');

it('BILL-ENUM-05: PlanInterval values are stable strings', function (): void {
    expect(PlanInterval::Monthly->value)->toBe('monthly')
        ->and(PlanInterval::Yearly->value)->toBe('yearly');
})->group('BILL-ENUM-05');

it('BILL-ENUM-06: UsageCategory values are stable strings', function (): void {
    expect(UsageCategory::Messages->value)->toBe('messages')
        ->and(UsageCategory::AiTokens->value)->toBe('ai_tokens')
        ->and(UsageCategory::Contacts->value)->toBe('contacts');
})->group('BILL-ENUM-06');

it('BILL-ENUM-07: SubscriptionStatus has labels', function (): void {
    expect(SubscriptionStatus::Active->label())->toBe('Active')
        ->and(SubscriptionStatus::Pending->label())->toBe('Pending')
        ->and(SubscriptionStatus::Cancelled->label())->toBe('Cancelled');
})->group('BILL-ENUM-07');

it('BILL-ENUM-08: PlanInterval has labels', function (): void {
    expect(PlanInterval::Monthly->label())->toBe('Monthly')
        ->and(PlanInterval::Yearly->label())->toBe('Yearly');
})->group('BILL-ENUM-08');

it('BILL-ENUM-09: UsageCategory has labels', function (): void {
    expect(UsageCategory::Messages->label())->toBe('Messages')
        ->and(UsageCategory::AiTokens->label())->toBe('AI Tokens')
        ->and(UsageCategory::Contacts->label())->toBe('Contacts');
})->group('BILL-ENUM-09');
