<?php

namespace App\Services;

use App\Events\MatchFound;
use App\Game\Board;
use App\Game\Randomizer;
use App\Models\GameMatch;
use App\Models\QueueEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rating-aware matchmaking queue.
 *
 * Every join/poll re-runs pairing under one atomic cache lock, so two players
 * can never both be paired with the same opponent. The acceptable rating gap
 * widens the longer either player has waited.
 */
class Matchmaker
{
    public const BASE_WINDOW = 150;

    public const WINDOW_GROWTH_PER_SECOND = 25;

    public const MAX_WINDOW = 1000;

    /** Entries not polled for this long are treated as gone. */
    public const STALE_AFTER_SECONDS = 10;

    /** A player nobody has been paired with for this long plays one of the house bots instead. */
    public const BOT_AFTER_SECONDS = 15;

    public function __construct(private BotRoster $bots) {}

    /**
     * Puts the user in the queue (or refreshes their entry) and tries to pair them.
     */
    public function join(User $user): ?GameMatch
    {
        $paired = false;

        $match = Cache::lock('matchmaking', 10)->block(5, function () use ($user, &$paired) {
            // Checked under the lock: a concurrent poll (a second tab) may have just paired this user.
            if ($active = $user->activeMatch()) {
                QueueEntry::query()->where('user_id', $user->id)->delete();

                return $active;
            }

            QueueEntry::query()->where('last_seen_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))->delete();

            $entry = QueueEntry::query()->firstOrNew(['user_id' => $user->id], ['joined_at' => now()]);
            $entry->fill(['rating' => $user->rating, 'last_seen_at' => now()])->save();

            $opponent = $this->findOpponent($entry);
            if ($opponent === null && $this->secondsUntilBot($entry) > 0) {
                return null;
            }

            $paired = true;

            return DB::transaction(function () use ($user, $entry, $opponent) {
                QueueEntry::query()->whereKey(array_filter([$entry->id, $opponent?->id]))->delete();

                return $opponent !== null
                    ? $this->createMatch($opponent->user, $user)
                    : $this->createMatch($user, $this->bots->closestTo($user->rating));
            });
        });

        if ($paired) {
            foreach ($match->players as $player) {
                if (! $player->user->isBot()) {
                    MatchFound::dispatch($player->user_id, $match->id);
                }
            }
        }

        return $match;
    }

    public function leave(User $user): void
    {
        QueueEntry::query()->where('user_id', $user->id)->delete();
    }

    public function createMatch(User $first, User $second): GameMatch
    {
        $match = GameMatch::create([
            'seed' => random_int(0, 4294967295),
            'status' => GameMatch::STATUS_ACTIVE,
            'starts_at' => now()->addSeconds(GameMatch::COUNTDOWN_SECONDS),
        ]);

        $firstPiece = (new Randomizer($match->seed))->piece(0);
        foreach ([$first, $second] as $slot => $user) {
            $match->players()->create([
                'user_id' => $user->id,
                'slot' => $slot,
                'board' => Board::empty()->toString(),
                'current' => $firstPiece,
                'rating_before' => $user->rating,
            ]);
        }

        return $match->load('players.user');
    }

    /**
     * The closest-rated waiting player inside the allowed window (oldest first on ties).
     */
    private function findOpponent(QueueEntry $entry): ?QueueEntry
    {
        return QueueEntry::query()
            ->with('user')
            ->where('user_id', '!=', $entry->user_id)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (QueueEntry $other) => abs($other->rating - $entry->rating) <= max($this->window($entry), $this->window($other)))
            ->sortBy(fn (QueueEntry $other) => abs($other->rating - $entry->rating))
            ->first();
    }

    public function secondsUntilBot(QueueEntry $entry): int
    {
        return max(0, self::BOT_AFTER_SECONDS - (int) $entry->joined_at->diffInSeconds(now()));
    }

    public function window(QueueEntry $entry): int
    {
        $waited = (int) $entry->joined_at->diffInSeconds(now());

        return min(self::BASE_WINDOW + self::WINDOW_GROWTH_PER_SECOND * $waited, self::MAX_WINDOW);
    }
}
