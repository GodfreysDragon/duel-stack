<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells a queued player which match they were paired into.
 */
class MatchFound implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(public int $userId, public int $matchId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'match.found';
    }

    /**
     * @return array{match_id: int}
     */
    public function broadcastWith(): array
    {
        return ['match_id' => $this->matchId];
    }
}
