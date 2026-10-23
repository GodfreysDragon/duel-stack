<?php

namespace App\Exceptions;

use App\Models\MatchPlayer;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A lock the server will not accept. The 409 response carries the player's
 * authoritative state so the client can resynchronise and keep playing.
 * Rejections are normal game flow, so they are not logged as errors.
 */
class LockRejected extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message, public readonly ?MatchPlayer $player = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $player = $this->player?->fresh();

        return response()->json([
            'message' => $this->getMessage(),
            'state' => $player?->stateForClient(),
            'pending_garbage' => $player?->pendingGarbageForClient() ?? [],
        ], 409);
    }
}
