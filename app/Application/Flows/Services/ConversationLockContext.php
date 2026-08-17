<?php

declare(strict_types=1);

namespace App\Application\Flows\Services;

use Illuminate\Cache\Lock as BaseLock;
use Illuminate\Contracts\Cache\Lock;

/**
 * Registra locks de conversación que ya posee el proceso actual. Evita que un
 * job ejecutado por la cola sync intente readquirir su propio lock Redis.
 */
final class ConversationLockContext
{
    /** @var array<string, array{depth: int, lock: Lock}> */
    private array $held = [];

    public function enter(string $tenantId, string $conversationId, Lock $lock): void
    {
        $key = $this->key($tenantId, $conversationId);
        $entry = $this->held[$key] ?? null;
        $this->held[$key] = [
            'depth' => ($entry['depth'] ?? 0) + 1,
            'lock' => $entry['lock'] ?? $lock,
        ];
    }

    public function leave(string $tenantId, string $conversationId): void
    {
        $key = $this->key($tenantId, $conversationId);
        $entry = $this->held[$key] ?? null;
        $remaining = ($entry['depth'] ?? 0) - 1;

        if ($remaining <= 0) {
            unset($this->held[$key]);

            return;
        }

        $this->held[$key]['depth'] = $remaining;
    }

    public function held(string $tenantId, string $conversationId): bool
    {
        $entry = $this->held[$this->key($tenantId, $conversationId)] ?? null;

        return $entry !== null
            && $entry['depth'] > 0
            && $entry['lock'] instanceof BaseLock
            && $entry['lock']->isOwnedByCurrentProcess();
    }

    public function refreshHeld(string $tenantId, string $conversationId, int $seconds): bool
    {
        $entry = $this->held[$this->key($tenantId, $conversationId)] ?? null;

        return $entry !== null
            && $entry['depth'] > 0
            && $entry['lock'] instanceof BaseLock
            && $entry['lock']->isOwnedByCurrentProcess()
            && $entry['lock']->refresh($seconds);
    }

    private function key(string $tenantId, string $conversationId): string
    {
        return $tenantId.':'.$conversationId;
    }
}
