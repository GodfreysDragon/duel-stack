<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Garbage rows sent to a player, waiting to rise into their board. `lines` is what is still to rise:
 * the target's own attacks cancel it down, and a batch cancelled to 0 never rises.
 */
#[DateFormat('Y-m-d H:i:s.v')]
#[Fillable(['target_player_id', 'lines', 'hole', 'applied_at_piece', 'created_at'])]
class GarbageBatch extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Batches that have neither risen nor been cancelled.
     *
     * @param  Builder<GarbageBatch>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('applied_at_piece')->where('lines', '>', 0);
    }

    /**
     * @return BelongsTo<MatchPlayer, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(MatchPlayer::class, 'target_player_id');
    }

    /**
     * @return array{id: int, lines: int, hole: int}
     */
    public function toClientArray(): array
    {
        return ['id' => $this->id, 'lines' => $this->lines, 'hole' => $this->hole];
    }
}
