<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_bot' => $this->isBot(),
            'rating' => $this->rating,
            'games_played' => $this->games_played,
            'wins' => $this->wins,
            'losses' => $this->losses,
            'total_lines' => $this->total_lines,
            'best_score' => $this->best_score,
        ];
    }
}
