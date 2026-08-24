<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('stripe_price_id_monthly', 255)->nullable()->after('price_yearly');
            $table->string('stripe_price_id_yearly', 255)->nullable()->after('stripe_price_id_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['stripe_price_id_monthly', 'stripe_price_id_yearly']);
        });
    }
};
