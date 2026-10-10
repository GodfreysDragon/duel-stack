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
        $user = $request->user();
        $match = $matchmaker->join($user);
        $entry = $match === null ? QueueEntry::query()->firstWhere('user_id', $user->id) : null;

        // Another player's poll can pair this user between join() releasing its lock and the lookup above.
        if ($match === null && $entry === null) {
            $match = $user->activeMatch();
        }

        if ($match !== null) {
            return response()->json(['status' => 'matched', 'match_id' => $match->id]);
        }

        return response()->json([
            'status' => 'searching',
            'players_waiting' => QueueEntry::query()->count(),
            'rating_window' => $entry !== null ? $matchmaker->window($entry) : Matchmaker::BASE_WINDOW,
            'bot_in' => $entry !== null ? $matchmaker->secondsUntilBot($entry) : Matchmaker::BOT_AFTER_SECONDS,
        ]);
    }

    public function destroy(Request $request, Matchmaker $matchmaker): Response
    {
        $matchmaker->leave($request->user());

        return response()->noContent();
    }
}
