<?php

namespace App\Services;

use App\Events\BoardUpdated;
use App\Events\GarbageSent;
use App\Exceptions\LockRejected;
use App\Game\Board;
use App\Game\InvalidLock;
use App\Game\Randomizer;
use App\Game\Rules;
use App\Game\Simulation;
use App\Models\GameMatch;
use App\Models\GarbageBatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Validates and applies one piece lock submitted by a player.
 *
 * Each lock runs in a transaction holding the match row lock, so the two
 * players' locks (and any garbage they send each other) are serialised.
 */
class LockProcessor
{
    /** Clock-skew allowance for the first lock after the countdown. */
    private const START_TOLERANCE_MS = 500;

    public function __construct(private MatchFinisher $finisher) {}

    /**
     * @param  array{piece_number: int, piece: string, rotation: int, x: int, y: int, held: bool, garbage_ids: list<int>}  $input
     * @return array{state: array<string, mixed>, cleared: list<int>, attack: int, sent: array{id: int, lines: int, hole: int}|null, match_over: bool}
     *
     * @throws LockRejected
     */
    public function submit(GameMatch $match, User $user, array $input): array
    {
        $outcome = DB::transaction(function () use ($match, $user, $input) {
            $match = GameMatch::query()->lockForUpdate()->findOrFail($match->id);
            $player = $match->players()->whereBelongsTo($user)->lockForUpdate()->firstOrFail();

            if ($match->isFinished()) {
                throw new LockRejected('The match is over.', $player);
            }
            if ($match->elapsedMs() < -self::START_TOLERANCE_MS) {
                throw new LockRejected('The match has not started yet.', $player);
            }
            if ($input['piece_number'] !== $player->pieces) {
                throw new LockRejected("Expected piece #{$player->pieces}, got #{$input['piece_number']}.", $player);
            }

            $garbage = $this->claimedGarbage($player, $input['garbage_ids']);
            $garbageRows = $garbage->map(fn (GarbageBatch $batch) => ['lines' => $batch->lines, 'hole' => $batch->hole])->all();

            try {
                $result = Simulation::applyLock($player->toState(), new Randomizer($match->seed), [
                    'piece' => $input['piece'],
                    'rotation' => $input['rotation'],
                    'x' => $input['x'],
                    'y' => $input['y'],
                    'held' => $input['held'],
                    'garbage' => $garbageRows,
                ]);
            } catch (InvalidLock $e) {
                throw new LockRejected($e->getMessage(), $player);
            }

            if ($result['cleared'] === []) {
                $this->ensureOverdueGarbageApplied($player, $garbage->count());
            }

            $pieceNumber = $player->pieces;
            $player->fillFromState($result['state'])->fill([
                'last_active_at' => now(),
                'last_seen_at' => now(),
                'garbage_received' => $player->garbage_received + $garbage->sum('lines'),
                'garbage_sent' => $player->garbage_sent + $result['attack'],
            ])->save();

            GarbageBatch::query()->whereKey($garbage->modelKeys())->update(['applied_at_piece' => $pieceNumber]);

            $player->moves()->create([
                'piece_number' => $pieceNumber,
                'piece' => $input['piece'],
                'rotation' => $input['rotation'],
                'x' => $input['x'],
                'y' => $input['y'],
                'held' => $input['held'],
                'garbage' => $garbageRows ?: null,
                'cleared' => count($result['cleared']),
                'attack' => $result['attack'],
                'at_ms' => max(0, $match->elapsedMs()),
            ]);

            $opponent = $match->players()->where('id', '!=', $player->id)->lockForUpdate()->firstOrFail();

            $sent = null;
            if ($result['attack'] > 0 && ! $opponent->topped_out) {
                $sent = $opponent->incomingGarbage()->create([
                    'lines' => $result['attack'],
                    'hole' => random_int(0, Board::WIDTH - 1),
                    'created_at' => now(),
                ]);
            }

            if ($player->topped_out) {
                $this->finisher->finish($match, $opponent, MatchFinisher::REASON_TOP_OUT);
            }

            return compact('player', 'sent', 'result');
        });

        ['player' => $player, 'sent' => $sent, 'result' => $result] = $outcome;

        broadcast(new BoardUpdated($player))->toOthers();
        if ($sent !== null) {
            broadcast(new GarbageSent($match, $sent, $player->user_id))->toOthers();
        }

        return [
            'state' => $player->stateForClient(),
            'cleared' => $result['cleared'],
            'attack' => $result['attack'],
            'sent' => $sent?->toClientArray(),
            'match_over' => $player->topped_out,
        ];
    }

    /**
     * The batches the client says it inserted: they must be the oldest pending ones, in order.
     *
     * @param  list<int>  $ids
     * @return Collection<int, GarbageBatch>
     */
    private function claimedGarbage(MatchPlayer $player, array $ids)
    {
        $pending = $player->incomingGarbage()->whereNull('applied_at_piece')->limit(count($ids))->get();

        if ($pending->modelKeys() !== $ids) {
            throw new LockRejected('Garbage must rise oldest first and only once.', $player);
        }

        return $pending;
    }

    /**
     * A lock that clears nothing must take in every batch the player has had time to receive.
     */
    private function ensureOverdueGarbageApplied(MatchPlayer $player, int $claimedCount): void
    {
        $overdue = $player->incomingGarbage()
            ->whereNull('applied_at_piece')
            ->where('created_at', '<=', now()->subMilliseconds(Rules::GARBAGE_GRACE_MS))
            ->count();

        if ($overdue > $claimedCount) {
            throw new LockRejected('Incoming garbage was not applied.', $player);
        }
    }
}
