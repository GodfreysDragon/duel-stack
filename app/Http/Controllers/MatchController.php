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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MatchController extends Controller
{
    public const LIVE_LIST_SIZE = 20;

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
                'garbage_through' => $you->garbageThrough(),
                'pending_garbage' => $you->pendingGarbageForClient(),
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
     * Sent every few seconds by a running, visible game, so a player between locks is not mistaken for one who left.
     */
    public function heartbeat(Request $request, GameMatch $match): Response
    {
        Gate::authorize('play', $match);

        if (! $match->isFinished()) {
            $match->players()->whereBelongsTo($request->user())->update(['last_seen_at' => now()]);
        }

        return response()->noContent();
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

            abort_unless($this->opponentOf($match, $request)->hasAbandoned($match), 409, 'Your opponent is still playing.');

            $winner = $match->players()->whereBelongsTo($request->user())->firstOrFail();
            $finisher->finish($match, $winner, MatchFinisher::REASON_ABANDONED);

            return response()->json(['match' => MatchResource::make($match->load('players.user'))]);
        });
    }

    /**
     * Matches being played right now, newest first, for spectators to pick from.
     */
    public function live(): AnonymousResourceCollection
    {
        $matches = GameMatch::query()->active()->with('players.user')->latest('id')->limit(self::LIVE_LIST_SIZE)->get();

        return MatchResource::collection($matches);
    }

    /**
     * Any match, live or finished, as a spectator sees it: both boards, but no player's private resume state.
     * Live updates follow on the public match.{id}.watch channel.
     */
    public function watch(GameMatch $match): JsonResponse
    {
        return response()->json([
            'match' => MatchResource::make($match->load('players.user')),
            'server_time' => now()->format('Y-m-d\TH:i:s.vP'),
        ]);
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
