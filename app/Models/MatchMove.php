<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted lock, kept so a finished match can be replayed.
 */
#[Fillable(['match_player_id', 'piece_number', 'piece', 'rotation', 'x', 'y', 'held', 'garbage', 'cleared', 'attack', 'at_ms'])]
class MatchMove extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'held' => 'boolean',
            'garbage' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MatchPlayer, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(MatchPlayer::class, 'match_player_id');
    }
}
