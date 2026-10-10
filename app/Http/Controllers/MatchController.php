<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitLockRequest;
use App\Http\Resources\MatchResource;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Services\LockProcessor;
use App\Services\MatchFinisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MatchController extends Controller
{
    /** How long an opponent may go without locking a piece before the other player can claim the win. */
    public const ABANDON_AFTER_SECONDS = 20;

    /**
     * The match, plus (for a player) their own authoritative state to resume from after a reload.
     */
    public function show(Request $request, GameMatch $match): JsonResponse
    {
        Gate::authorize('view', $match);

        $match->load('players.user');
        $you = $match->players->firstWhere('user_id', $request->user()->id);

        return response()->json([
            'match' => MatchResource::make($match),
            'you' => $you ? [
                'state' => $you->stateForClient(),
                'pending_garbage' => $you->incomingGarbage()->whereNull('applied_at_piece')->get()->map->toClientArray(),
            ] : null,
            'server_time' => now()->format('Y-m-d\TH:i:s.vP'),
        ]);
    }

    public function lock(SubmitLockRequest $request, GameMatch $match, LockProcessor $processor): JsonResponse
    {
        Gate::authorize('play', $match);

        return response()->json($processor->submit($match, $request->user(), $request->lock()));
    }

    public function forfeit(Request $request, GameMatch $match, MatchFinisher $finisher): JsonResponse
    {
        Gate::authorize('play', $match);

        return DB::transaction(function () use ($request, $match, $finisher) {
            $match = GameMatch::query()->lockForUpdate()->findOrFail($match->id);
            abort_if($match->isFinished(), 409, 'The match is already over.');

            $finisher->finish($match, $this->opponentOf($match, $request), MatchFinisher::REASON_FORFEIT);

            return response()->json(['match' => MatchResource::make($match->load('players.user'))]);
        });
    }

    /**
     * Win by default when the opponent has stopped playing (closed the tab, lost connection, ...).
     */
    public function claim(Request $request, GameMatch $match, MatchFinisher $finisher): JsonResponse
    {
        Gate::authorize('play', $match);

        return DB::transaction(function () use ($request, $match, $finisher) {
            $match = GameMatch::query()->lockForUpdate()->findOrFail($match->id);
            abort_if($match->isFinished(), 409, 'The match is already over.');

            $opponent = $this->opponentOf($match, $request);
            $lastSeen = $opponent->last_active_at ?? $match->starts_at;

            abort_if($lastSeen->diffInSeconds(now()) < self::ABANDON_AFTER_SECONDS, 409, 'Your opponent is still playing.');

            $winner = $match->players()->whereBelongsTo($request->user())->firstOrFail();
            $finisher->finish($match, $winner, MatchFinisher::REASON_ABANDONED);

            return response()->json(['match' => MatchResource::make($match->load('players.user'))]);
        });
    }

    /**
     * Every accepted lock of a finished match, for the replay viewer.
     */
    public function replay(GameMatch $match): JsonResponse
    {
        abort_unless($match->isFinished(), 403, 'Replays are available once the match is over.');

        $match->load(['players.user', 'players.moves']);

        return response()->json([
            'match' => MatchResource::make($match),
            'moves' => $match->players->mapWithKeys(fn (MatchPlayer $player) => [
                $player->user_id => $player->moves->map->only(['piece_number', 'piece', 'rotation', 'x', 'y', 'held', 'garbage', 'cleared', 'attack', 'at_ms']),
            ]),
        ]);
    }

    private function opponentOf(GameMatch $match, Request $request): MatchPlayer
    {
        return $match->players()->where('user_id', '!=', $request->user()->id)->firstOrFail();
    }
}
