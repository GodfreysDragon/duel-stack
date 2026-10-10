<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LeaderboardController extends Controller
{
    public const SIZE = 50;

    /**
     * Top players by Elo rating, or by best single-match score.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['sort' => ['sometimes', Rule::in(['rating', 'best_score'])]]);

        $users = User::query()
            ->where('games_played', '>', 0)
            ->orderByDesc($validated['sort'] ?? 'rating')
            ->orderBy('id')
            ->limit(self::SIZE)
            ->get();

        return UserResource::collection($users);
    }
}
