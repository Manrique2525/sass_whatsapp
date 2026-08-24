<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('provider', 50);
            $table->string('provider_customer_id', 255);
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'provider'], 'billing_customers_tenant_provider_unique');
            $table->unique(['provider', 'provider_customer_id'], 'billing_customers_provider_customer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_customers');
    }
};
