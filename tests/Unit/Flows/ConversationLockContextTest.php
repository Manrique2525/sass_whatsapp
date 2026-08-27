<?php

declare(strict_types=1);

use App\Application\Flows\Services\ConversationLockContext;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

test('HANDOFF-LOCK-01: contexto sync solo reporta un lock que aún pertenece al proceso', function (): void {
    $lock = Cache::store('array')->lock('handoff-lock-context-test', 30);
    expect($lock->get())->toBeTrue();

    $context = new ConversationLockContext;
    $context->enter('tenant-a', 'conversation-a', $lock);

    expect($context->held('tenant-a', 'conversation-a'))->toBeTrue()
        ->and($context->refreshHeld('tenant-a', 'conversation-a', 60))->toBeTrue();

    $lock->release();

    expect($context->held('tenant-a', 'conversation-a'))->toBeFalse();

    $context->leave('tenant-a', 'conversation-a');

    expect($context->held('tenant-a', 'conversation-a'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// F29-U3-LOCK-* — reentrancia, contención, cross-tenant y ramas de BaseLock
// ---------------------------------------------------------------------------

test('F29-U3-LOCK-02: reentrancia — enter anidado requiere doble leave para liberar', function (): void {
    $lock = Cache::store('array')->lock('handoff-lock-reentrant', 30);
    $lock->get();

    $context = new ConversationLockContext;
    $context->enter('t', 'c', $lock);
    $context->enter('t', 'c', $lock);

    expect($context->held('t', 'c'))->toBeTrue();

    $context->leave('t', 'c');
    expect($context->held('t', 'c'))->toBeTrue();

    $context->leave('t', 'c');
    expect($context->held('t', 'c'))->toBeFalse();

    $lock->release();
});

test('F29-U3-LOCK-03: conversaciones distintas son independientes', function (): void {
    $lockA = Cache::store('array')->lock('handoff-lock-ca', 30);
    $lockB = Cache::store('array')->lock('handoff-lock-cb', 30);
    $lockA->get();
    $lockB->get();

    $context = new ConversationLockContext;
    $context->enter('t', 'conv-a', $lockA);

    expect($context->held('t', 'conv-a'))->toBeTrue()
        ->and($context->held('t', 'conv-b'))->toBeFalse();

    $context->enter('t', 'conv-b', $lockB);
    expect($context->held('t', 'conv-b'))->toBeTrue();

    $lockA->release();
    $lockB->release();
});

test('F29-U3-LOCK-04: mismo conversation_id en tenants distintos es independiente', function (): void {
    $lockA = Cache::store('array')->lock('handoff-lock-t1c', 30);
    $lockB = Cache::store('array')->lock('handoff-lock-t2c', 30);
    $lockA->get();
    $lockB->get();

    $context = new ConversationLockContext;
    $context->enter('tenant-a', 'conversation-1', $lockA);

    expect($context->held('tenant-a', 'conversation-1'))->toBeTrue()
        ->and($context->held('tenant-b', 'conversation-1'))->toBeFalse();

    $context->enter('tenant-b', 'conversation-1', $lockB);
    expect($context->held('tenant-b', 'conversation-1'))->toBeTrue();

    $lockA->release();
    $lockB->release();
});

test('F29-U3-LOCK-05: refreshHeld tras liberar el lock devuelve false y deja de estar held', function (): void {
    $lock = Cache::store('array')->lock('handoff-lock-refresh', 30);
    $lock->get();

    $context = new ConversationLockContext;
    $context->enter('t', 'c', $lock);

    expect($context->refreshHeld('t', 'c', 45))->toBeTrue();

    $lock->release();

    expect($context->refreshHeld('t', 'c', 45))->toBeFalse()
        ->and($context->held('t', 'c'))->toBeFalse();
});

test('F29-U3-LOCK-06: leave sin enter previo no lanza y no deja estado residual', function (): void {
    $context = new ConversationLockContext;

    $context->leave('t', 'c');

    expect($context->held('t', 'c'))->toBeFalse();
});

test('F29-U3-LOCK-07: lock que no es BaseLock no se reporta held ni refrescable', function (): void {
    $notBaseLock = new class implements Lock
    {
        public function get($callback = null): mixed
        {
            return $callback ? $callback() : true;
        }

        public function attempt(?callable $callback = null): mixed
        {
            return true;
        }

        public function block($callback, $seconds = null): mixed
        {
            return $callback();
        }

        public function release(): bool
        {
            return true;
        }

        public function forceRelease(): void {}

        public function owner(): string
        {
            return 'me';
        }

        public function isOwnedByCurrentProcess(): bool
        {
            return true;
        }
    };

    $context = new ConversationLockContext;
    $context->enter('t', 'c', $notBaseLock);

    expect($context->held('t', 'c'))->toBeFalse()
        ->and($context->refreshHeld('t', 'c', 60))->toBeFalse();
});
