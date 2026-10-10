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
        Schema::table('match_players', function (Blueprint $table) {
            // Last heartbeat from a running, visible game (see MatchController::heartbeat).
            $table->dateTime('last_seen_at', 3)->nullable()->after('last_active_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('match_players', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
