<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FailedLoginAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_api_login_emits_audit_event(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);

        $audit = AuditLog::where('action', 'user.login_failed')->latest()->first();

        $this->assertNotNull($audit, 'Failed login should create audit_log record');
        $this->assertSame('invalid_credentials', $audit->data['reason']);
        $this->assertNotNull($audit->ip_address);
        $this->assertNotNull($audit->user_agent);
    }

    public function test_failed_login_does_not_store_email_in_audit(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong',
        ]);

        $audit = AuditLog::where('action', 'user.login_failed')->latest()->first();

        $this->assertNotNull($audit);
        $this->assertArrayNotHasKey('email', $audit->data);
        $this->assertArrayNotHasKey('password', $audit->data);
    }

    public function test_failed_login_does_not_distinguish_user_exists(): void
    {
        // Existing user
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong',
        ]);

        $audit1 = AuditLog::where('action', 'user.login_failed')->latest()->first();

        // Non-existing user
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'wrong',
        ]);

        $audit2 = AuditLog::where('action', 'user.login_failed')->latest()->first();

        $this->assertSame($audit1->data['reason'], $audit2->data['reason']);
    }

    public function test_failed_login_includes_request_id(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);

        $audit = AuditLog::where('action', 'user.login_failed')->latest()->first();

        $this->assertNotNull($audit);
        // request_id is injected by AuditLogger into data
        $this->assertArrayHasKey('request_id', $audit->data);
    }

    public function test_successful_api_login_does_not_emit_failed_event(): void
    {
        // Create a real user first
        $user = User::factory()->create([
            'email' => 'real@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'real@example.com',
            'password' => 'password123',
        ])->assertOk();

        $failedCount = AuditLog::where('action', 'user.login_failed')->count();

        $this->assertSame(0, $failedCount);
    }

    public function test_failed_login_does_not_store_password_hash(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'mysecretpassword',
        ]);

        $audit = AuditLog::where('action', 'user.login_failed')->latest()->first();

        $this->assertNotNull($audit);
        $serialized = serialize($audit->data);
        $this->assertStringNotContainsString('mysecretpassword', $serialized);
        $this->assertStringNotContainsString('$2y$', $serialized);
    }
}
