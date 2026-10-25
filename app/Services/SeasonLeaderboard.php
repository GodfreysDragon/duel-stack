<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Season;
use App\Models\SeasonStanding;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Season rankings: live for the running season, from the saved standings for past ones, and the rollover
 * from one season to the next.
 */
class SeasonLeaderboard
{
    public const SORTS = ['rating', 'best_score'];

    /**
     * The season's players, best first. A running season ranks humans with a finished match in it by their
     * current rating (or best score), with their games, wins and best score from this season only.
     *
     * @return Collection<int, array{user: User, rating: int, games_played: int, wins: int, best_score: int}>
     */
    public function rows(Season $season, string $sort = 'rating', ?int $limit = null): Collection
    {
        if ($season->ends_at !== null) {
            return $this->standings($season, $sort, $limit);
        }

        $finishedInSeason = fn (Builder $query) => $query
            ->where('status', GameMatch::STATUS_FINISHED)
            ->where('ended_at', '>=', $season->starts_at->format('Y-m-d H:i:s.v'));
        $playedInSeason = fn (Builder $query) => $query->whereHas('gameMatch', $finishedInSeason);

        return User::query()
            ->humans()
            ->whereHas('matchPlayers', $playedInSeason)
            ->withCount(['matchPlayers as season_games' => $playedInSeason, 'wonMatches as season_wins' => $finishedInSeason])
            ->withMax(['matchPlayers as season_best_score' => $playedInSeason], 'score')
            ->orderByDesc($sort === 'best_score' ? 'season_best_score' : 'rating')
            ->orderBy('id')
            ->when($limit, fn (Builder $query, int $limit) => $query->limit($limit))
            ->get()
            ->map(fn (User $user) => [
                'user' => $user,
                'rating' => $user->rating,
                'games_played' => (int) $user->getAttribute('season_games'),
                'wins' => (int) $user->getAttribute('season_wins'),
                'best_score' => (int) $user->getAttribute('season_best_score'),
            ]);
    }

    /**
     * @return Collection<int, array{user: User, rating: int, games_played: int, wins: int, best_score: int}>
     */
    private function standings(Season $season, string $sort, ?int $limit): Collection
    {
        return $season->standings()
            ->with('user')
            ->when($sort === 'best_score', fn ($query) => $query->orderByDesc('best_score'), fn ($query) => $query->orderBy('rank'))
            ->orderBy('id')
            ->when($limit, fn ($query, int $limit) => $query->limit($limit))
            ->get()
            // Standings are deleted with their user, so a missing one is only possible mid-deletion.
            ->flatMap(fn (SeasonStanding $standing) => $standing->user === null ? [] : [[
                'user' => $standing->user,
                'rating' => $standing->rating,
                'games_played' => $standing->games_played,
                'wins' => $standing->wins,
                'best_score' => $standing->best_score,
            ]]);
    }

    /**
     * Ends the running season: saves its final standings, pulls every human's rating halfway back to the
     * starting rating, puts the house bots back at theirs, and opens the next season.
     */
    public function roll(): Season
    {
        return DB::transaction(function () {
            $season = Season::current();

            foreach ($this->rows($season)->values() as $index => $row) {
                $season->standings()->create([
                    'user_id' => $row['user']->id,
                    'rank' => $index + 1,
                    'rating' => $row['rating'],
                    'games_played' => $row['games_played'],
                    'wins' => $row['wins'],
                    'best_score' => $row['best_score'],
                ]);
            }
            $season->update(['ends_at' => now()]);

            User::query()->humans()->where('rating', '!=', Season::BASE_RATING)->eachById(
                fn (User $user) => User::query()->whereKey($user->id)->update(['rating' => Season::softReset($user->rating)]),
            );
            foreach (BotRoster::BOTS as $level => $bot) {
                User::query()->where('bot_level', $level)->update(['rating' => $bot['rating']]);
            }

            return Season::query()->create(['number' => $season->number + 1, 'starts_at' => now()]);
        });
    }
}
