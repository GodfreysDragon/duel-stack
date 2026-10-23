<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ratings:decay')]
#[Description('Lower the ratings of players who have stopped playing, so the leaderboard stays current')]
class DecayRatings extends Command
{
    /** Days without a rated match before decay starts. */
    public const INACTIVE_AFTER_DAYS = 14;

    /** Points lost per run (it is scheduled daily). */
    public const POINTS_PER_DAY = 10;

    /** Decay never takes a rating below this, so it only trims high ratings that are no longer defended. */
    public const FLOOR = 1200;

    public function handle(): int
    {
        $decayed = User::query()
            ->humans()
            ->where('last_played_at', '<', now()->subDays(self::INACTIVE_AFTER_DAYS))
            ->where('rating', '>', self::FLOOR)
            ->update([
                'rating' => DB::raw(sprintf('CASE WHEN rating - %1$d < %2$d THEN %2$d ELSE rating - %1$d END', self::POINTS_PER_DAY, self::FLOOR)),
            ]);

        $this->info("Decayed {$decayed} rating(s).");

        return self::SUCCESS;
    }
}
