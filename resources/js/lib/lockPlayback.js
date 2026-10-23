import { pieceAlongLock } from '../engine/playback.js';

// Each lock is played back over most of the gap since the previous one, within these bounds.
const PLAYBACK = { firstMs: 500, minMs: 200, maxMs: 900, share: 0.9, maxBacklog: 3 };

/**
 * Plays a player's locks back as a falling piece, for boards that get no live falling-piece whispers: a house
 * bot's board, and every board a spectator watches. The view runs a fraction of a second behind the server.
 *
 * @param view   reactive { board, active } the renderer reads; `active` is set to the falling piece
 * @param show   applies a board.updated event to the view once its piece has landed
 */
export function createLockPlayback(view, show) {
    let locks = [];
    let lastLockAt = 0;

    return {
        /** Queues a board.updated event that carries its `lock`. */
        queue(event) {
            const now = performance.now();
            const gap = lastLockAt ? now - lastLockAt : PLAYBACK.firstMs / PLAYBACK.share;
            lastLockAt = now;
            const durationMs = Math.min(Math.max(gap * PLAYBACK.share, PLAYBACK.minMs), PLAYBACK.maxMs);
            locks.push({ event, durationMs, startedAt: null });
        },

        /** Advances the playback; call once per rendered frame. */
        frame() {
            // After the tab was hidden, skip ahead instead of replaying every missed piece.
            while (locks.length > PLAYBACK.maxBacklog) show(locks.shift().event);

            const now = performance.now();
            while (locks.length) {
                const item = locks[0];
                item.startedAt ??= now;
                const progress = (now - item.startedAt) / item.durationMs;
                if (progress < 1) {
                    view.active = pieceAlongLock(view.board, item.event.lock, progress);
                    return;
                }
                locks.shift();
                show(item.event);
            }
        },

        /** Shows every queued lock at once (the match is over). */
        flush() {
            locks.splice(0).forEach((item) => show(item.event));
        },
    };
}
