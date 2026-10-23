<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['name', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults so a just-registered user serialises complete stats.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'rating' => 1000,
        'games_played' => 0,
        'wins' => 0,
        'losses' => 0,
        'total_lines' => 0,
        'best_score' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'rating' => 'integer',
            'last_played_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<MatchPlayer, $this>
     */
    public function matchPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class);
    }

    /**
     * @return HasMany<GameMatch, $this>
     */
    public function wonMatches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'winner_id');
    }

    /**
     * One of the house bots (see App\Services\BotRoster).
     */
    public function isBot(): bool
    {
        return $this->bot_level !== null;
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function humans(Builder $query): void
    {
        $query->whereNull('bot_level');
    }

    /**
     * The match this user is currently playing, if any.
     */
    public function activeMatch(): ?GameMatch
    {
        return GameMatch::query()
            ->active()
            ->whereHas('players', fn ($query) => $query->whereBelongsTo($this))
            ->latest('id')
            ->first();
    }

    /**
     * Public profile fields, shared by the API and presence channels.
     *
     * @return array{id: int, name: string, rating: int}
     */
    public function toPublicArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'rating' => $this->rating];
    }
}
