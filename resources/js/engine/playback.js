import { SPAWN_X, SPAWN_Y } from './constants.js';
import { fits } from './board.js';

// Share of the playback spent rotating and shifting at the top; the rest is the fall.
const MOVE_SHARE = 0.4;

/**
 * Where a piece is part-way through a replayed lock, as the falling piece an opponent would
 * have seen: it spawns as the engine spawns it, rotates and shifts one step at a time, then
 * falls straight to its resting place. `progress` runs from 0 to 1.
 *
 * Used for the house bots, which lock pieces on the server without a live falling piece.
 */
export function pieceAlongLock(board, lock, progress) {
    const { piece: type, rotation, x, y } = lock;
    const startY = fits(board, type, 0, SPAWN_X, SPAWN_Y + 1) ? SPAWN_Y + 1 : SPAWN_Y;

    // A three-quarter turn is one turn the other way.
    const turns = rotation === 3 ? [3] : Array.from({ length: rotation }, (_, i) => i + 1);
    const shift = Math.sign(x - SPAWN_X);
    const steps = [
        ...turns.map((r) => ({ rotation: r, x: SPAWN_X })),
        ...Array.from({ length: Math.abs(x - SPAWN_X) }, (_, i) => ({ rotation, x: SPAWN_X + shift * (i + 1) })),
    ];

    const t = Math.min(Math.max(progress, 0), 1);
    if (t < MOVE_SHARE) {
        const done = Math.floor((t / MOVE_SHARE) * (steps.length + 1));
        const at = done === 0 ? { rotation: 0, x: SPAWN_X } : steps[Math.min(done, steps.length) - 1];
        return { type, rotation: at.rotation, x: at.x, y: startY };
    }

    const fall = (t - MOVE_SHARE) / (1 - MOVE_SHARE);
    return { type, rotation, x, y: Math.round(startY + (y - startY) * fall) };
}
