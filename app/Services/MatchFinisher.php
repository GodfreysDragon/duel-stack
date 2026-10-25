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
            $loser = $players->sole(fn (MatchPlayer $player) => $player->id !== $winner->id);
            $winner = $players->sole(fn (MatchPlayer $player) => $player->id === $winner->id);
            [$winnerRating, $loserRating] = Elo::afterGame($winner->rating_before, $loser->rating_before);

            $this->record($winner, $winnerRating, won: true);
            $this->record($loser, $loserRating, won: false);
        } else {
            $players->each(fn (MatchPlayer $player) => $player->update(['rating_after' => $player->rating_before]));
        }

        DB::afterCommit(fn () => MatchEnded::dispatch(GameMatch::query()->with('players.user')->findOrFail($match->id)));
    }

    /**
     * The rating change is applied relative to the user's current rating: bots play many matches at once, so the
     * rating may have moved since this match's rating_before.
     */
    private function record(MatchPlayer $player, int $newRating, bool $won): void
    {
        $player->update(['rating_after' => $newRating]);

        $delta = $newRating - $player->rating_before;
        $tally = $won ? 'wins = wins + 1' : 'losses = losses + 1';

        DB::update(
            'UPDATE users SET rating = CASE WHEN rating + ? < ? THEN ? ELSE rating + ? END, games_played = games_played + 1, '
            .$tally.', total_lines = total_lines + ?, best_score = CASE WHEN best_score < ? THEN ? ELSE best_score END,'
            .' last_played_at = ?, updated_at = ? WHERE id = ?',
            [$delta, Elo::FLOOR, Elo::FLOOR, $delta, $player->lines, $player->score, $player->score, now(), now(), $player->user_id],
        );
    }
}
