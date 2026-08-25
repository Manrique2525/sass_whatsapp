<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id');
            $table->string('category', 50);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('quantity');
            $table->string('idempotency_key', 255)->nullable();
            $table->string('status', 20)->default('reserved');
            $table->timestamp('expires_at');
            $table->timestamp('reserved_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('subscription_id')
                ->references('id')
                ->on('subscriptions')
                ->cascadeOnDelete();

            $table->unique(
                ['tenant_id', 'subscription_id', 'category', 'idempotency_key'],
                'usage_reservations_idempotency_active_idx',
            );
            $table->index(['tenant_id', 'category', 'status', 'expires_at'], 'usage_reservations_active_idx');

            if (config('database.default') === 'pgsql') {
                DB::statement('ALTER TABLE usage_reservations ADD CONSTRAINT usage_reservations_quantity_positive CHECK (quantity > 0)');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_reservations');
    }
};
