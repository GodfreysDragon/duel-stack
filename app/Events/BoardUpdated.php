<?php

namespace App\Events;

use App\Models\MatchPlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A player's board after a lock the server accepted, for the opponent's view.
 */
class BoardUpdated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public MatchPlayer $player) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PresenceChannel("match.{$this->player->game_match_id}")];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->player->user_id,
            'board' => $this->player->board,
            'score' => $this->player->score,
            'lines' => $this->player->lines,
            'pieces' => $this->player->pieces,
            'garbage_sent' => $this->player->garbage_sent,
            'topped_out' => $this->player->topped_out,
        ];
    }
}
