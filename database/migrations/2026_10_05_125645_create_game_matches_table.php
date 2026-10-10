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
        Schema::create('game_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('seed');
            $table->string('status', 16)->default('active');
            $table->dateTime('starts_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            $table->foreignId('winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 16)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_matches');
    }
};
