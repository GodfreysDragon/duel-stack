<?php

namespace App\Policies;

use App\Models\GameMatch;
use App\Models\User;

class GameMatchPolicy
{
    /**
     * Live matches are visible to their players only; finished ones are public (replays).
     */
    public function view(User $user, GameMatch $match): bool
    {
        return $match->isFinished() || $this->play($user, $match);
    }

    /**
     * Submitting locks, forfeiting and claiming a win.
     */
    public function play(User $user, GameMatch $match): bool
    {
        return $match->players()->whereBelongsTo($user)->exists();
    }
}
