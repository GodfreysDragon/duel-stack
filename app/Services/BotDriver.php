<?php

namespace App\Services;

use App\Exceptions\LockRejected;
use App\Game\Bot;
use App\Game\Randomizer;
use App\Game\Simulation;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use Illuminate\Support\Facades\DB;

/**
 * Plays the bots' side of every live bot match. Stateless: a bot's next piece is due its think
 * time (see Bot::thinkMilliseconds) after its last lock, so any number of ticks, or a
 * restarted driver, plays at the same pace.
 *
 * Bot locks go through LockProcessor, so bots follow exactly the rules players do.
 */
class BotDriver
{
    public function __construct(private LockProcessor $processor, private MatchFinisher $finisher) {}

    /**
     * Plays one piece for every bot that is due. Returns how many pieces were locked.
     */
    public function tick(): int
    {
        $bots = MatchPlayer::query()
            ->where('topped_out', false)
            ->whereHas('user', fn ($query) => $query->whereNotNull('bot_level'))
            ->whereHas('gameMatch', fn ($query) => $query->active())
            ->with(['user', 'gameMatch'])
            ->get();

        // One bot's failure (a database hiccup, say) must not stop the others or the daemon.
        return $bots->filter(fn (MatchPlayer $bot) => rescue(fn () => $this->play($bot), false))->count();
    }

    private function play(MatchPlayer $bot): bool
    {
        $match = $bot->gameMatch;
        $level = $bot->user->bot_level;

        $lastLock = $bot->last_active_at ?? $match->starts_at;
        if ($lastLock->diffInMilliseconds(now()) < Bot::thinkMilliseconds($level, $match->seed, $bot->pieces)) {
            return false;
        }

        if ($this->claimIfAbandoned($match, $bot)) {
            return false;
        }

        $state = $bot->toState();
        $randomizer = new Randomizer($match->seed);
        $move = Bot::chooseMove($state, $randomizer, Bot::LEVELS[$level]['mistake_rate']);
        if ($move === null) {
            return false;
        }

        // Like the client: garbage rises on a lock that clears nothing.
        $pending = $bot->incomingGarbage()->pending()->get();
        $clears = Simulation::applyLock($state, $randomizer, [...$move, 'garbage' => []])['cleared'] !== [];
        $garbageIds = $clears ? [] : $pending->modelKeys();

        try {
            $this->processor->submit($match, $bot->user, [...$move, 'piece_number' => $bot->pieces, 'garbage_ids' => $garbageIds]);
        } catch (LockRejected) {
            return false; // the state moved on (another driver, or new garbage); retry next tick
        }

        return true;
    }

    /**
     * The bot takes the win when its opponent has left, as a player would.
     */
    private function claimIfAbandoned(GameMatch $match, MatchPlayer $bot): bool
    {
        $opponent = $match->players()->whereKeyNot($bot->id)->firstOrFail();
        if (! $opponent->hasAbandoned($match)) {
            return false;
        }

        DB::transaction(function () use ($match, $bot) {
            $locked = GameMatch::query()->lockForUpdate()->findOrFail($match->id);
            if (! $locked->isFinished()) {
                $this->finisher->finish($locked, $bot, MatchFinisher::REASON_ABANDONED);
            }
        });

        return true;
    }
}
