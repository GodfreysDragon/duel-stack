<?php

namespace App\Events;

use App\Models\GameMatch;
use App\Models\GarbageBatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Garbage on its way to the opponent, who must raise it on their next non-clearing lock.
 */
class GarbageSent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameMatch $match, public GarbageBatch $batch, public int $fromUserId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("match.{$this->match->id}")];
    }

    public function broadcastAs(): string
    {
        return 'garbage.sent';
    }

    /**
     * @return array{from_user_id: int, batch: array{id: int, lines: int, hole: int}}
     */
    public function broadcastWith(): array
    {
        return ['from_user_id' => $this->fromUserId, 'batch' => $this->batch->toClientArray()];
    }
}
