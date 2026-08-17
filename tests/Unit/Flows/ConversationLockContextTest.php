<?php

declare(strict_types=1);

use App\Application\Flows\Services\ConversationLockContext;
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
