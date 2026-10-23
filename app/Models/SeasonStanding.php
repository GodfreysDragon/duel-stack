<?php

namespace App\Models;

use Database\Factories\SeasonStandingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's final place in a season that has ended.
 */
#[Fillable(['season_id', 'user_id', 'rank', 'rating', 'games_played', 'wins', 'best_score'])]
class SeasonStanding extends Model
{
    /** @use HasFactory<SeasonStandingFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
