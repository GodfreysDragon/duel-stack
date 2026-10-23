<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Season;
use App\Services\SeasonLeaderboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaderboardController extends Controller
{
    public const SIZE = 50;

    /**
     * A season's top players by Elo rating or by best single-match score: the running season by default,
     * or the final standings of a past one.
     */
    public function __invoke(Request $request, SeasonLeaderboard $leaderboard): JsonResponse
    {
        $validated = $request->validate([
            'sort' => ['sometimes', Rule::in(SeasonLeaderboard::SORTS)],
            'season' => ['sometimes', 'integer', Rule::exists('seasons', 'number')],
        ]);

        $current = Season::current();
        $season = isset($validated['season']) ? Season::query()->where('number', $validated['season'])->sole() : $current;

        return response()->json([
            'season' => $season->toClientArray(),
            'seasons' => Season::query()->orderByDesc('number')->get()->map->toClientArray(),
            'players' => $leaderboard->rows($season, $validated['sort'] ?? 'rating', self::SIZE)->map(fn (array $row) => [
                ...$row,
                'user' => UserResource::make($row['user']),
            ]),
        ]);
    }
}
