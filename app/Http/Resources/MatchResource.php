<?php

namespace App\Http\Resources;

use App\Models\GameMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GameMatch
 */
class MatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seed' => $this->seed,
            'status' => $this->status,
            'starts_at' => $this->starts_at->format('Y-m-d\TH:i:s.vP'),
            'ended_at' => $this->ended_at?->format('Y-m-d\TH:i:s.vP'),
            'winner_id' => $this->winner_id,
            'end_reason' => $this->end_reason,
            'players' => MatchPlayerResource::collection($this->whenLoaded('players')),
        ];
    }
}
