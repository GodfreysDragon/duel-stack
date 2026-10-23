<?php

namespace App\Console\Commands;

use App\Services\SeasonLeaderboard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('seasons:roll')]
#[Description('End the running season: keep its standings, soft-reset ratings and start the next one')]
class RollSeason extends Command
{
    public function handle(SeasonLeaderboard $leaderboard): int
    {
        $next = $leaderboard->roll();

        $this->info('Season '.($next->number - 1)." ended; season {$next->number} has begun.");

        return self::SUCCESS;
    }
}
