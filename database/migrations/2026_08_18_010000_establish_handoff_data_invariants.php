<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invariantes de datos para Human Handoff (FASE 15, UNIDAD 1, ADR-051/052).
 *
 * Los tenants de assignments/participants se derivan exclusivamente de la
 * conversación referenciada. La migración aborta si detecta datos que no
 * puedan backfillearse o más de una assignment abierta por conversación.
 */
return new class extends Migration
{
    private const OPEN_ASSIGNMENT_UNIQUE = 'conversation_assignments_open_unique';

    public function up(): void
    {
        Schema::table('conversation_assignments', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('id');
        });

        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('id');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('sent_by_user_id')
                ->nullable()
                ->after('conversation_id');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('handoff_requested_at')->nullable()->after('bot_paused');
        });

        $this->backfillTenantId('conversation_assignments');
        $this->backfillTenantId('conversation_participants');
        $this->assertTenantBackfillComplete('conversation_assignments');
        $this->assertTenantBackfillComplete('conversation_participants');
        $this->assertNoDuplicateOpenAssignments();

        $this->makeTenantIdRequired('conversation_assignments');
        $this->makeTenantIdRequired('conversation_participants');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'id'],
                'conversations_tenant_id_id_unique',
            );
            $table->index(
                ['tenant_id', 'handoff_requested_at'],
                'conversations_tenant_handoff_requested_at_index',
            );
        });

        Schema::table('conversation_assignments', function (Blueprint $table): void {
            $table->foreign('tenant_id', 'conversation_assignments_tenant_id_foreign')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign(
                ['tenant_id', 'conversation_id'],
                'conversation_assignments_tenant_id_conversation_id_foreign',
            )
                ->references(['tenant_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->index(
                ['tenant_id', 'conversation_id'],
                'conversation_assignments_tenant_conversation_index',
            );
            $table->index(
                ['tenant_id', 'agent_id'],
                'conversation_assignments_tenant_agent_index',
            );
        });

        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->foreign('tenant_id', 'conversation_participants_tenant_id_foreign')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign(
                ['tenant_id', 'conversation_id'],
                'conversation_participants_tenant_id_conversation_id_foreign',
            )
                ->references(['tenant_id', 'id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->index(
                ['tenant_id', 'conversation_id'],
                'conversation_participants_tenant_conversation_index',
            );
            $table->index(
                ['tenant_id', 'user_id'],
                'conversation_participants_tenant_user_index',
            );
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreign('sent_by_user_id', 'messages_sent_by_user_id_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->index('sent_by_user_id', 'messages_sent_by_user_id_index');
        });

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON conversation_assignments (conversation_id) WHERE unassigned_at IS NULL',
            self::OPEN_ASSIGNMENT_UNIQUE,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::OPEN_ASSIGNMENT_UNIQUE);

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_sent_by_user_id_index');
            $table->dropForeign(['sent_by_user_id']);
            $table->dropColumn('sent_by_user_id');
        });

        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropIndex('conversation_participants_tenant_conversation_index');
            $table->dropIndex('conversation_participants_tenant_user_index');
            $table->dropForeign(['tenant_id', 'conversation_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('conversation_assignments', function (Blueprint $table): void {
            $table->dropIndex('conversation_assignments_tenant_conversation_index');
            $table->dropIndex('conversation_assignments_tenant_agent_index');
            $table->dropForeign(['tenant_id', 'conversation_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique('conversations_tenant_id_id_unique');
            $table->dropIndex('conversations_tenant_handoff_requested_at_index');
            $table->dropColumn('handoff_requested_at');
        });
    }

    private function backfillTenantId(string $table): void
    {
        DB::statement(sprintf(
            'UPDATE %1$s SET tenant_id = (SELECT tenant_id FROM conversations WHERE conversations.id = %1$s.conversation_id) WHERE tenant_id IS NULL',
            $table,
        ));
    }

    private function assertTenantBackfillComplete(string $table): void
    {
        if (DB::table($table)->whereNull('tenant_id')->exists()) {
            throw new RuntimeException(sprintf(
                'No se pudo derivar tenant_id para todos los registros de %s.',
                $table,
            ));
        }
    }

    private function assertNoDuplicateOpenAssignments(): void
    {
        $duplicate = DB::table('conversation_assignments')
            ->select('conversation_id')
            ->whereNull('unassigned_at')
            ->groupBy('conversation_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new RuntimeException(
                'Existen conversaciones con más de una assignment abierta; cierre los duplicados antes de migrar.',
            );
        }
    }

    private function makeTenantIdRequired(string $table): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id SET NOT NULL");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->uuid('tenant_id')->change();
        });
    }
};
