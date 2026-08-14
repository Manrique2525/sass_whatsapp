<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participantes de una conversación (FASE 8, ADR-031).
 *
 * Modela quién estuvo/está involucrado en la conversación (agentes y, en el
 * futuro, bots). `role` replica el rol del usuario en el tenant en el momento
 * de participar (owner/admin/agent). `joined_at`/`left_at` permiten reconstruir
 * la secuencia de participación; el participante actual es `left_at IS NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('conversation_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->unique(['conversation_id', 'user_id'], 'conversation_participants_conversation_user_unique');
            $table->index(['user_id', 'conversation_id'], 'conversation_participants_user_conversation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
