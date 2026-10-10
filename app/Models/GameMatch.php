<?php

namespace App\Models;

use Database\Factories\GameMatchFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A 1v1 ranked match. ("Match" is a reserved word in PHP 8.)
 */
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable(['seed', 'status', 'starts_at', 'ended_at', 'winner_id', 'end_reason'])]
class GameMatch extends Model
{
    /** @use HasFactory<GameMatchFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FINISHED = 'finished';

    /** Countdown between pairing and the first piece. */
    public const COUNTDOWN_SECONDS = 4;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<MatchPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(MatchPlayer::class)->orderBy('slot');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    /**
     * @param  Builder<GameMatch>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function hasStarted(): bool
    {
        return now()->greaterThanOrEqualTo($this->starts_at);
    }

    /**
     * Milliseconds since the first piece (negative during the countdown).
     */
    public function elapsedMs(): int
    {
        return (int) $this->starts_at->diffInMilliseconds(now(), false);
    }
}
