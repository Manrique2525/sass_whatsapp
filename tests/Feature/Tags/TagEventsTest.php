<?php

declare(strict_types=1);

use App\Domain\Contacts\Enums\TagAssignmentOrigin;
use App\Domain\Contacts\Events\TagAssigned;
use App\Domain\Contacts\Events\TagRemoved;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Tag Events Tests (FASE 20 U3)
|--------------------------------------------------------------------------
|
| TAG-EVT-01..08 — Event dispatch: TagAssigned, TagRemoved, payloads,
| afterCommit behavior.
| Corren en SQLite :memory:.
|
*/

it('TAG-EVT-01: TagAssigned has correct payload', function (): void {
    $event = new TagAssigned(
        tenantId: 't-1',
        contactId: 'c-1',
        tagId: 'tag-1',
        tagName: 'VIP',
        origin: TagAssignmentOrigin::Manual,
    );

    expect($event->tenantId)->toBe('t-1');
    expect($event->contactId)->toBe('c-1');
    expect($event->tagId)->toBe('tag-1');
    expect($event->tagName)->toBe('VIP');
    expect($event->origin)->toBe(TagAssignmentOrigin::Manual);
    expect($event->conversationId)->toBeNull();
    expect($event->originExecutionId)->toBeNull();
    expect($event->afterCommit)->toBeTrue();
})->group('TAG-EVT-01');

it('TAG-EVT-02: TagRemoved has correct payload', function (): void {
    $event = new TagRemoved(
        tenantId: 't-1',
        contactId: 'c-1',
        tagId: 'tag-1',
        tagName: 'VIP',
    );

    expect($event->tenantId)->toBe('t-1');
    expect($event->contactId)->toBe('c-1');
    expect($event->tagId)->toBe('tag-1');
    expect($event->tagName)->toBe('VIP');
    expect($event->afterCommit)->toBeTrue();
})->group('TAG-EVT-02');

it('TAG-EVT-03: TagAssignmentOrigin enum values are manual and flow', function (): void {
    expect(TagAssignmentOrigin::Manual->value)->toBe('manual');
    expect(TagAssignmentOrigin::Flow->value)->toBe('flow');
})->group('TAG-EVT-03');

it('TAG-EVT-04: TagAssigned with flow origin includes originExecutionId', function (): void {
    $event = new TagAssigned(
        tenantId: 't-1',
        contactId: 'c-1',
        tagId: 'tag-1',
        tagName: 'VIP',
        origin: TagAssignmentOrigin::Flow,
        conversationId: 'conv-1',
        originExecutionId: 'exec-1',
    );

    expect($event->origin)->toBe(TagAssignmentOrigin::Flow);
    expect($event->conversationId)->toBe('conv-1');
    expect($event->originExecutionId)->toBe('exec-1');
})->group('TAG-EVT-04');

it('TAG-EVT-05: TagAssigned with manual origin has no execution context', function (): void {
    $event = new TagAssigned(
        tenantId: 't-1',
        contactId: 'c-1',
        tagId: 'tag-1',
        tagName: 'VIP',
        origin: TagAssignmentOrigin::Manual,
        conversationId: 'conv-1',
    );

    expect($event->originExecutionId)->toBeNull();
})->group('TAG-EVT-05');

it('TAG-EVT-06: TagAssigned is Dispatchable', function (): void {
    $event = new TagAssigned(
        tenantId: 't-1',
        contactId: 'c-1',
        tagId: 'tag-1',
        tagName: 'VIP',
        origin: TagAssignmentOrigin::Manual,
    );

    expect(class_uses($event))->toContain(Dispatchable::class);
})->group('TAG-EVT-06');

it('TAG-EVT-07: TagRemoved is Dispatchable', function (): void {
    $event = new TagRemoved(
        tenantId: 't-1',
        contactId: 'c-1',
        tagId: 'tag-1',
        tagName: 'VIP',
    );

    expect(class_uses($event))->toContain(Dispatchable::class);
})->group('TAG-EVT-07');

it('TAG-EVT-08: TagAssigned has public readonly properties', function (): void {
    $event = new TagAssigned(
        tenantId: 't-1',
        contactId: 'c-1',
        tagId: 'tag-1',
        tagName: 'VIP',
        origin: TagAssignmentOrigin::Manual,
        conversationId: 'conv-1',
        originExecutionId: 'exec-1',
    );

    $reflection = new ReflectionClass($event);
    $tenantProp = $reflection->getProperty('tenantId');
    expect($tenantProp->isPublic())->toBeTrue();
    expect($tenantProp->isReadOnly())->toBeTrue();
})->group('TAG-EVT-08');
