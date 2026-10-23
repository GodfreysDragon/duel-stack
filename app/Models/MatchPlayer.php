<?php

namespace App\Models;

use App\Game\Board;
use App\Game\PlayerState;
use Database\Factories\MatchPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable([
    'game_match_id', 'user_id', 'slot', 'board', 'current', 'next_index', 'hold', 'combo', 'b2b', 'score', 'lines',
    'pieces', 'topped_out', 'garbage_sent', 'garbage_received', 'last_active_at', 'last_seen_at', 'rating_before',
    'rating_after',
])]
class MatchPlayer extends Model
{
    /** @use HasFactory<MatchPlayerFactory> */
    use HasFactory;

    /** How long a player may go without a heartbeat or a lock before their opponent can claim the win. */
    public const ABANDON_AFTER_SECONDS = 20;

    /**
     * How long a player may go without locking a piece, heartbeats or not. Lock-delay resets refill at each new
     * lowest row and a hold respawns the piece at the top, so an honest piece can take minutes; this is far beyond it.
     */
    public const STALL_AFTER_SECONDS = 300;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'b2b' => 'boolean',
            'topped_out' => 'boolean',
            'last_active_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<GameMatch, $this>
     */
    public function gameMatch(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Garbage aimed at this player.
     *
     * @return HasMany<GarbageBatch, $this>
     */
    public function incomingGarbage(): HasMany
    {
        return $this->hasMany(GarbageBatch::class, 'target_player_id')->orderBy('id');
    }

    /**
     * Incoming garbage still waiting to rise, in the shape the client uses.
     *
     * @return list<array{id: int, lines: int, hole: int}>
     */
    public function pendingGarbageForClient(): array
    {
        return array_values($this->incomingGarbage()->pending()->get()->map->toClientArray()->all());
    }

    /**
     * @return HasMany<MatchMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(MatchMove::class)->orderBy('piece_number');
    }

    /**
     * Whether this player has left the match: no heartbeat or lock for a while, or no lock for far too long.
     */
    public function hasAbandoned(GameMatch $match): bool
    {
        $lastLock = $this->last_active_at ?? $match->starts_at;
        $lastSeen = max($this->last_seen_at ?? $match->starts_at, $lastLock);

        return $lastSeen->diffInSeconds(now()) >= self::ABANDON_AFTER_SECONDS
            || $lastLock->diffInSeconds(now()) >= self::STALL_AFTER_SECONDS;
    }

    public function toState(): PlayerState
    {
        return new PlayerState(
            board: Board::fromString($this->board),
            current: $this->current,
            nextIndex: $this->next_index,
            hold: $this->hold,
            combo: $this->combo,
            b2b: $this->b2b,
            score: $this->score,
            lines: $this->lines,
            pieces: $this->pieces,
            toppedOut: $this->topped_out,
        );
    }

    public function fillFromState(PlayerState $state): self
    {
        return $this->fill([
            'board' => $state->board->toString(),
            'current' => $state->current,
            'next_index' => $state->nextIndex,
            'hold' => $state->hold,
            'combo' => $state->combo,
            'b2b' => $state->b2b,
            'score' => $state->score,
            'lines' => $state->lines,
            'pieces' => $state->pieces,
            'topped_out' => $state->toppedOut,
        ]);
    }

    /**
     * Engine state in the shape the JS client uses (see initialState() in simulation.js).
     *
     * @return array<string, mixed>
     */
    public function stateForClient(): array
    {
        return [
            'board' => $this->board,
            'current' => $this->current,
            'nextIndex' => $this->next_index,
            'hold' => $this->hold,
            'combo' => $this->combo,
            'b2b' => $this->b2b,
            'score' => $this->score,
            'lines' => $this->lines,
            'pieces' => $this->pieces,
            'toppedOut' => $this->topped_out,
        ];
    }
}
