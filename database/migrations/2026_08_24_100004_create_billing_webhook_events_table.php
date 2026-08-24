<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('provider_event_id', 255);
            $table->string('type', 150);
            $table->string('status', 20)->default('pending');
            $table->timestamp('provider_created_at')->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('billing_customer_id')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'billing_webhook_events_provider_event_unique');

            $table->index(['provider', 'status'], 'billing_webhook_events_provider_status_index');
            $table->index('tenant_id', 'billing_webhook_events_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
    }
};
