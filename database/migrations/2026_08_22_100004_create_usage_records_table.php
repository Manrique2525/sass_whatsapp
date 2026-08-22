<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('subscription_id');
            $table->string('category', 50);
            $table->bigInteger('quantity')->default(1);
            $table->string('description', 255)->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('recorded_at');
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
                ['tenant_id', 'subscription_id', 'category', 'recorded_at'],
                'usage_records_unique_per_period',
            );
            $table->index(['tenant_id', 'category', 'recorded_at'], 'usage_records_category_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
