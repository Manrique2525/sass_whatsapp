<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot N:M contactos ↔ tags (FASE 7, preparado para FASE 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tag', function (Blueprint $table): void {
            $table->uuid('contact_id');
            $table->uuid('tag_id');
            $table->timestamps();

            $table->primary(['contact_id', 'tag_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tag');
    }
};
