<?php

namespace App\Services;

use App\Events\BoardUpdated;
use App\Events\GarbageSent;
use App\Exceptions\LockRejected;
use App\Game\Board;
use App\Game\InvalidLock;
use App\Game\Randomizer;
use App\Game\Reachability;
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
     * @return array{state: array<string, mixed>, cleared: list<int>, attack: int, sent: array{id: int, lines: int, hole: int}|null, garbage_through: int, pending_garbage: list<array{id: int, lines: int, hole: int}>, match_over: bool}
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
            $allowed = Rules::MAX_PIECES_PER_SECOND * max(0, $match->elapsedMs()) / 1000 + Rules::PACE_ALLOWANCE_PIECES;
            if ($player->pieces + 1 > $allowed) {
                throw new LockRejected('Pieces are arriving faster than anyone can play.', $player);
            }

            $garbage = $this->claimedGarbage($player, $input['garbage_ids']);
            $garbageRows = array_values($garbage->map(fn (GarbageBatch $batch) => ['lines' => $batch->lines, 'hole' => $batch->hole])->all());

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
            if (! Reachability::canReach($player->toState()->board, $input['piece'], $input['rotation'], $input['x'], $input['y'])) {
                throw new LockRejected('No sequence of moves reaches that position.', $player);
            }

            if ($result['cleared'] === []) {
                $this->ensureOverdueGarbageApplied($player, $garbage->count());
            }

            $pieceNumber = $player->pieces;
            $opponent = $match->players()->where('id', '!=', $player->id)->lockForUpdate()->firstOrFail();

            // An attack first cancels the attacker's own incoming garbage; only the rest is sent.
            $attack = $result['attack'] - $this->cancelIncomingGarbage($player, $result['attack']);
            $sent = null;
            if ($attack > 0 && ! $opponent->topped_out) {
                $sent = $opponent->incomingGarbage()->create([
                    'lines' => $attack,
                    'hole' => random_int(0, Board::WIDTH - 1),
                    'created_at' => now(),
                ]);
            }

            $player->fillFromState($result['state'])->fill([
                'last_active_at' => now(),
                'last_seen_at' => now(),
                'garbage_received' => $player->garbage_received + $garbage->sum('lines'),
                'garbage_sent' => $player->garbage_sent + ($sent->lines ?? 0),
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

            if ($player->topped_out) {
                $this->finisher->finish($match, $opponent, MatchFinisher::REASON_TOP_OUT);
            }

            return compact('player', 'sent', 'result');
        });

        ['player' => $player, 'sent' => $sent, 'result' => $result] = $outcome;

        broadcast(new BoardUpdated($player, [
            'piece' => $input['piece'],
            'rotation' => $input['rotation'],
            'x' => $input['x'],
            'y' => $input['y'],
        ]))->toOthers();
        if ($sent !== null) {
            broadcast(new GarbageSent($match, $sent, $player->user_id))->toOthers();
        }

        return [
            'state' => $player->stateForClient(),
            'cleared' => $result['cleared'],
            'attack' => $result['attack'],
            'sent' => $sent?->toClientArray(),
            // Read before the pending list, so no batch can fall between the two.
            'garbage_through' => $player->garbageThrough(),
            'pending_garbage' => $player->pendingGarbageForClient(),
            'match_over' => $player->topped_out,
        ];
    }

    /**
     * Uses an attack to cancel the player's own pending garbage, oldest first.
     *
     * @return int the lines of attack used up
     */
    private function cancelIncomingGarbage(MatchPlayer $player, int $attack): int
    {
        $remaining = $attack;
        foreach ($player->incomingGarbage()->pending()->get() as $batch) {
            if ($remaining === 0) {
                break;
            }
            $cancelled = min($batch->lines, $remaining);
            $batch->decrement('lines', $cancelled);
            $remaining -= $cancelled;
        }

        return $attack - $remaining;
    }

    /**
     * The batches the client says it inserted: they must be the oldest pending ones, in order.
     *
     * @param  list<int>  $ids
     * @return Collection<int, GarbageBatch>
     */
    private function claimedGarbage(MatchPlayer $player, array $ids)
    {
        $pending = $player->incomingGarbage()->pending()->limit(count($ids))->get();

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
            ->pending()
            ->where('created_at', '<=', now()->subMilliseconds(Rules::GARBAGE_GRACE_MS)->format('Y-m-d H:i:s.v'))
            ->count();

        if ($overdue > $claimedCount) {
            throw new LockRejected('Incoming garbage was not applied.', $player);
        }
    }
}
