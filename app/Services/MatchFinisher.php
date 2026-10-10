<?php

namespace App\Services;

use App\Events\MatchEnded;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a match: records the winner, updates Elo ratings and lifetime stats,
 * and announces the result. Callers must hold a row lock on the match.
 */
class MatchFinisher
{
    public const REASON_TOP_OUT = 'topout';

    public const REASON_FORFEIT = 'forfeit';

    public const REASON_ABANDONED = 'abandoned';

    public const REASON_NO_CONTEST = 'no_contest';

    public function finish(GameMatch $match, ?MatchPlayer $winner, string $reason): void
    {
        $players = $match->players()->with('user')->get();

        $match->update([
            'status' => GameMatch::STATUS_FINISHED,
            'ended_at' => now(),
            'winner_id' => $winner?->user_id,
            'end_reason' => $reason,
        ]);

        if ($winner !== null) {
            $loser = $players->firstWhere('id', '!=', $winner->id);
            $winner = $players->firstWhere('id', $winner->id);
            [$winnerRating, $loserRating] = Elo::afterGame($winner->rating_before, $loser->rating_before);

            $this->record($winner, $winnerRating, won: true);
            $this->record($loser, $loserRating, won: false);
        } else {
            $players->each(fn (MatchPlayer $player) => $player->update(['rating_after' => $player->rating_before]));
        }

        DB::afterCommit(fn () => MatchEnded::dispatch($match->fresh(['players.user'])));
    }

    private function record(MatchPlayer $player, int $newRating, bool $won): void
    {
        $player->update(['rating_after' => $newRating]);

        User::query()->whereKey($player->user_id)->update([
            'rating' => $newRating,
            'games_played' => DB::raw('games_played + 1'),
            $won ? 'wins' : 'losses' => DB::raw(($won ? 'wins' : 'losses').' + 1'),
            'total_lines' => DB::raw('total_lines + '.(int) $player->lines),
            'best_score' => DB::raw('CASE WHEN best_score < '.(int) $player->score.' THEN '.(int) $player->score.' ELSE best_score END'),
        ]);
    }
}
