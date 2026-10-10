<?php

namespace App\Http\Controllers;

use App\Http\Resources\MatchResource;
use App\Http\Resources\UserResource;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PlayerController extends Controller
{
    /**
     * A player's profile: lifetime stats, leaderboard rank and recent finished matches.
     */
    public function show(User $user): JsonResponse
    {
        $rank = $user->games_played > 0 && ! $user->isBot()
            ? User::query()->humans()->where('games_played', '>', 0)->where('rating', '>', $user->rating)->count() + 1
            : null;

        $recent = GameMatch::query()
            ->where('status', GameMatch::STATUS_FINISHED)
            ->whereHas('players', fn ($query) => $query->whereBelongsTo($user))
            ->with('players.user')
            ->latest('id')
            ->limit(15)
            ->get();

        return response()->json([
            'user' => UserResource::make($user),
            'rank' => $rank,
            'recent_matches' => MatchResource::collection($recent),
        ]);
    }
}
