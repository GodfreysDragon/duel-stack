<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('garbage_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_player_id')->constrained('match_players')->cascadeOnDelete();
            $table->unsignedTinyInteger('lines');
            $table->unsignedTinyInteger('hole');
            $table->unsignedInteger('applied_at_piece')->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['target_player_id', 'applied_at_piece']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('garbage_batches');
    }
};
