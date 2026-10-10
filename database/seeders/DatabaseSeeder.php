<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\BotRoster;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Two fresh accounts to play each other with (password: "password"),
     * plus a populated leaderboard and the house bots.
     */
    public function run(BotRoster $bots): void
    {
        User::factory()->create(['name' => 'alice']);
        User::factory()->create(['name' => 'bob']);

        User::factory(12)->veteran()->create();

        $bots->all();
    }
}
