<?php

namespace App\Console\Commands;

use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Services\MatchFinisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('matches:expire-idle {--minutes=2 : Idle time after which a match is abandoned}')]
#[Description('End active matches where neither player has locked a piece recently (no rating change)')]
class ExpireIdleMatches extends Command
{
    public function handle(MatchFinisher $finisher): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));
        $expired = 0;

        GameMatch::query()->active()->where('starts_at', '<', $cutoff)->with('players')->each(
            function (GameMatch $match) use ($cutoff, $finisher, &$expired) {
                $idle = $match->players->every(
                    fn (MatchPlayer $player) => ($player->last_active_at ?? $match->starts_at)->lessThan($cutoff),
                );
                if (! $idle) {
                    return;
                }

                DB::transaction(function () use ($match, $finisher, &$expired) {
                    $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);
                    if (! $locked->isFinished()) {
                        $finisher->finish($locked, null, MatchFinisher::REASON_NO_CONTEST);
                        $expired++;
                    }
                });
            },
        );

        $this->info("Expired {$expired} idle match(es).");

        return self::SUCCESS;
    }
}
