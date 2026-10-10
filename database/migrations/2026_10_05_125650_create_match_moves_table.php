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
        Schema::create('match_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_player_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('piece_number');
            $table->char('piece', 1);
            $table->unsignedTinyInteger('rotation');
            $table->tinyInteger('x');
            $table->tinyInteger('y');
            $table->boolean('held');
            $table->json('garbage')->nullable();
            $table->unsignedTinyInteger('cleared');
            $table->unsignedTinyInteger('attack');
            $table->unsignedInteger('at_ms');

            $table->unique(['match_player_id', 'piece_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_moves');
    }
};
