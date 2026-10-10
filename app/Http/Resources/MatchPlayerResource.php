<?php

namespace App\Http\Resources;

use App\Models\MatchPlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MatchPlayer
 */
class MatchPlayerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => UserResource::make($this->whenLoaded('user')),
            'slot' => $this->slot,
            'board' => $this->board,
            'score' => $this->score,
            'lines' => $this->lines,
            'pieces' => $this->pieces,
            'garbage_sent' => $this->garbage_sent,
            'garbage_received' => $this->garbage_received,
            'topped_out' => $this->topped_out,
            'rating_before' => $this->rating_before,
            'rating_after' => $this->rating_after,
        ];
    }
}
