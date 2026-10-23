<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->dateTime('starts_at', 3);
            // Null while the season is running.
            $table->dateTime('ends_at', 3)->nullable();
        });

        // The first season starts now, so every match from here on counts towards it.
        DB::table('seasons')->insert(['number' => 1, 'starts_at' => now()->format('Y-m-d H:i:s.v'), 'ends_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
