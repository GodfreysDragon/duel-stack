import { onBeforeUnmount, reactive, ref } from 'vue';
import { api } from '../lib/api';
import { echo } from '../lib/echo';
import { createLockPlayback } from '../lib/lockPlayback';
import { deserialize, emptyBoard } from '../engine/board.js';
import { Randomizer } from '../engine/randomizer.js';
import { preview } from '../engine/simulation.js';

/**
 * A spectator's view of a match: both boards, following the public match.{id}.watch channel. Falling
 * pieces are only whispered between the players, so a spectator sees each lock played back instead.
 */
export function useSpectateMatch(matchId) {
    const status = ref('loading'); // loading | live | finished | error
    const error = ref(null);
    const match = ref(null);
    const boards = [0, 1].map(() =>
        reactive({ board: emptyBoard(), active: null, hold: null, nextIndex: 1, score: 0, lines: 0, sent: 0, toppedOut: false }),
    );
    const channelName = `match.${matchId}.watch`;
    let randomizer = null;
    let playbacks = [];

    /** Applies a board.updated event, or a player from the API: they use the same field names. */
    function show(view, e) {
        Object.assign(view, {
            board: deserialize(e.board),
            active: null,
            hold: e.hold,
            nextIndex: e.next_index,
            score: e.score,
            lines: e.lines,
            sent: e.garbage_sent,
            toppedOut: e.topped_out,
        });
    }

    async function load() {
        try {
            const data = await api('GET', `/api/matches/${matchId}/watch`);
            match.value = data.match;
            randomizer = new Randomizer(data.match.seed);
            data.match.players.forEach((player, slot) => show(boards[slot], player));
            if (data.match.status === 'finished') {
                status.value = 'finished';
                return;
            }

            playbacks = boards.map((view) => createLockPlayback(view, (e) => show(view, e)));
            status.value = 'live';
            echo.channel(channelName)
                .listen('.board.updated', (e) => {
                    const slot = match.value.players.findIndex((p) => p.user.id === e.user_id);
                    if (slot === -1) return;
                    if (e.lock) playbacks[slot].queue(e);
                    else show(boards[slot], e);
                })
                .listen('.match.ended', (e) => finish(e.match));
        } catch (e) {
            error.value = e.status === 404 ? 'No such match.' : e.message;
            status.value = 'error';
        }
    }

    function finish(finalMatch) {
        playbacks.forEach((playback) => playback.flush());
        match.value = finalMatch;
        status.value = 'finished';
    }

    function view(slot) {
        const b = boards[slot];
        return {
            board: b.board,
            active: b.active,
            hold: b.hold,
            next: randomizer ? preview({ nextIndex: b.nextIndex }, randomizer, 5) : [],
            toppedOut: b.toppedOut,
        };
    }

    /** One rendered frame, with `focus` (a player slot) on the large board. */
    function frame(focus) {
        playbacks.forEach((playback) => playback.frame());
        return { me: view(focus), opponent: view(1 - focus) };
    }

    load();

    onBeforeUnmount(() => echo.leave(channelName));

    return { status, error, match, boards, frame };
}
