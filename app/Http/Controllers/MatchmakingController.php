<?php

namespace App\Http\Controllers;

use App\Models\QueueEntry;
use App\Services\Matchmaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MatchmakingController extends Controller
{
    /**
     * Join the queue, or keep a place in it: clients call this every couple of seconds while searching.
     */
    public function store(Request $request, Matchmaker $matchmaker): JsonResponse
    {
        $match = $matchmaker->join($request->user());

        if ($match !== null) {
            return response()->json(['status' => 'matched', 'match_id' => $match->id]);
        }

        $entry = QueueEntry::query()->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'status' => 'searching',
            'players_waiting' => QueueEntry::query()->count(),
            'rating_window' => $matchmaker->window($entry),
        ]);
    }

    public function destroy(Request $request, Matchmaker $matchmaker): Response
    {
        $matchmaker->leave($request->user());

        return response()->noContent();
    }
}
