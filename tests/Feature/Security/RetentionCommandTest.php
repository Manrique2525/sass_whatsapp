<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_prune_removes_old_records(): void
    {
        // Insert old record (100 days ago)
        DB::table('audit_logs')->insert([
            'id' => Str::uuid(),
            'action' => 'test.old',
            'data' => json_encode(['key' => 'value']),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        // Insert recent record (10 days ago)
        DB::table('audit_logs')->insert([
            'id' => Str::uuid(),
            'action' => 'test.recent',
            'data' => json_encode(['key' => 'value']),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        $this->artisan('audit:prune', ['--days' => 90])
            ->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'test.old']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'test.recent']);
    }

    public function test_audit_prune_dry_run_does_not_delete(): void
    {
        DB::table('audit_logs')->insert([
            'id' => Str::uuid(),
            'action' => 'test.old',
            'data' => json_encode([]),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $this->artisan('audit:prune', ['--days' => 90, '--dry-run' => true])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'test.old']);
    }

    public function test_audit_prune_preserves_recent_records(): void
    {
        DB::table('audit_logs')->insert([
            'id' => Str::uuid(),
            'action' => 'test.recent',
            'data' => json_encode([]),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        $this->artisan('audit:prune', ['--days' => 90])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'test.recent']);
    }

    public function test_audit_prune_respects_cutoff_boundary(): void
    {
        // Exactly at boundary (90 days ago, 1 hour old) — should be preserved
        DB::table('audit_logs')->insert([
            'id' => Str::uuid(),
            'action' => 'test.boundary',
            'data' => json_encode([]),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'created_at' => now()->subDays(90)->addHour(),
            'updated_at' => now()->subDays(90)->addHour(),
        ]);

        $this->artisan('audit:prune', ['--days' => 90])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'test.boundary']);
    }

    public function test_failed_jobs_prune_removes_old_records(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test exception',
            'failed_at' => now()->subDays(40),
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test exception',
            'failed_at' => now()->subDays(5),
        ]);

        $this->artisan('queue:prune-failed', ['--days' => 30])
            ->assertOk();

        $oldCount = DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subDays(30))
            ->count();

        $this->assertSame(0, $oldCount);

        $recentCount = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays(30))
            ->count();

        $this->assertSame(1, $recentCount);
    }

    public function test_failed_jobs_prune_dry_run_does_not_delete(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Test',
            'failed_at' => now()->subDays(40),
        ]);

        $this->artisan('queue:prune-failed', ['--days' => 30, '--dry-run' => true])
            ->assertOk();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }
}
