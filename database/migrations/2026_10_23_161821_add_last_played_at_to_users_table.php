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
        Schema::table('users', function (Blueprint $table) {
            // When the user's last rated match ended; ratings of long-inactive players decay (see ratings:decay).
            $table->dateTime('last_played_at')->nullable()->index()->after('best_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_played_at']);
            $table->dropColumn('last_played_at');
        });
    }
};
