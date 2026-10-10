<?php

use App\Models\GameMatch;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Matchmaking results for one player.
Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

// A live match: both players' boards, garbage and the result. Presence lets
// each side see whether the other is still connected.
Broadcast::channel('match.{match}', function (User $user, GameMatch $match) {
    if (! $match->players()->whereBelongsTo($user)->exists()) {
        return false;
    }

    return $user->toPublicArray();
});
