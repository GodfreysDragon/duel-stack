<?php

namespace App\Models;

use App\Game\Board;
use App\Game\PlayerState;
use Database\Factories\MatchPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'game_match_id', 'user_id', 'slot', 'board', 'current', 'next_index', 'hold', 'combo', 'b2b', 'score', 'lines',
    'pieces', 'topped_out', 'garbage_sent', 'garbage_received', 'last_active_at', 'rating_before', 'rating_after',
])]
class MatchPlayer extends Model
{
    /** @use HasFactory<MatchPlayerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'b2b' => 'boolean',
            'topped_out' => 'boolean',
            'last_active_at' => 'immutable_datetime',
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
     * @return HasMany<MatchMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(MatchMove::class)->orderBy('piece_number');
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
