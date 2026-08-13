<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_tenant_id')->nullable()->after('email_verified_at');

            $table->index('current_tenant_id', 'users_current_tenant_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_current_tenant_id_index');

            $table->dropColumn('current_tenant_id');
        });
    }
};
