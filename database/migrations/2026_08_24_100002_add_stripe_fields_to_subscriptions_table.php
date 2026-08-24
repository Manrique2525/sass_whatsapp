<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('stripe_subscription_id', 255)->nullable()->unique()->after('plan_id');
            $table->boolean('cancel_at_period_end')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['stripe_subscription_id', 'cancel_at_period_end']);
        });
    }
};
