<?php

namespace App\Http\Controllers;

use App\Http\Resources\MatchResource;
use App\Http\Resources\UserResource;
use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlayerController extends Controller
{
    public const MATCHES_PER_PAGE = 15;

    /**
     * A player's profile: lifetime stats and leaderboard rank.
     */
    public function show(User $user): JsonResponse
    {
        $rank = $user->games_played > 0 && ! $user->isBot()
            ? User::query()->humans()->where('games_played', '>', 0)->where('rating', '>', $user->rating)->count() + 1
            : null;

        return response()->json([
            'user' => UserResource::make($user),
            'rank' => $rank,
        ]);
    }

    /**
     * A player's finished matches, newest first, a page at a time.
     */
    public function matches(User $user): AnonymousResourceCollection
    {
        $matches = GameMatch::query()
            ->where('status', GameMatch::STATUS_FINISHED)
            ->whereHas('players', fn ($query) => $query->whereBelongsTo($user))
            ->with('players.user')
            ->latest('id')
            ->paginate(self::MATCHES_PER_PAGE);

        return MatchResource::collection($matches);
    }
}
