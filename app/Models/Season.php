<?php

namespace App\Models;

use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ranked season. The leaderboard covers the running one; when it ends (see seasons:roll) its final standings
 * are kept and every rating is pulled halfway back towards the starting rating.
 */
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable(['number', 'starts_at', 'ends_at'])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    public $timestamps = false;

    public const BASE_RATING = 1000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<SeasonStanding, $this>
     */
    public function standings(): HasMany
    {
        return $this->hasMany(SeasonStanding::class);
    }

    /**
     * The running season, opened the first time it is needed.
     */
    public static function current(): self
    {
        return self::query()->whereNull('ends_at')->latest('number')->first()
            ?? self::query()->create(['number' => (int) self::query()->max('number') + 1, 'starts_at' => now()]);
    }

    /**
     * A rating carried into the next season: halfway back to the starting rating.
     */
    public static function softReset(int $rating): int
    {
        return self::BASE_RATING + intdiv($rating - self::BASE_RATING, 2);
    }

    /**
     * @return array{number: int, starts_at: string, ends_at: string|null}
     */
    public function toClientArray(): array
    {
        return [
            'number' => $this->number,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
        ];
    }
}
