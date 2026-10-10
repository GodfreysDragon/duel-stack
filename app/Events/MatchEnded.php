<?php

namespace App\Events;

use App\Http\Resources\MatchResource;
use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The final result, with rating changes, sent to both players.
 */
class MatchEnded implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(public GameMatch $match) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("match.{$this->match->id}")];
    }

    public function broadcastAs(): string
    {
        return 'match.ended';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['match' => MatchResource::make($this->match)->resolve()];
    }
}
