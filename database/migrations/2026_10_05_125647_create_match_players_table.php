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
        Schema::create('match_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');

            // Authoritative engine state (see App\Game\PlayerState).
            $table->char('board', 220);
            $table->char('current', 1);
            $table->unsignedInteger('next_index')->default(1);
            $table->char('hold', 1)->nullable();
            $table->smallInteger('combo')->default(-1);
            $table->boolean('b2b')->default(false);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('lines')->default(0);
            $table->unsignedInteger('pieces')->default(0);
            $table->boolean('topped_out')->default(false);

            $table->unsignedInteger('garbage_sent')->default(0);
            $table->unsignedInteger('garbage_received')->default(0);
            $table->dateTime('last_active_at', 3)->nullable();
            $table->unsignedSmallInteger('rating_before');
            $table->unsignedSmallInteger('rating_after')->nullable();
            $table->timestamps();

            $table->unique(['game_match_id', 'user_id']);
            $table->index(['user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_players');
    }
};
