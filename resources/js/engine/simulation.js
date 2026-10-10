import {
    addGarbage,
    clearLines,
    emptyBoard,
    fits,
    isEmpty,
    isGrounded,
    lockedOut,
    place,
    scoreLock,
    spawnFits,
} from './board.js';

/**
 * The turn-by-turn rules shared by the live game, the bot, the replay viewer
 * and (as a PHP port in app/Game/Simulation.php) the server's validator.
 *
 * A player's state only changes when a piece locks. Everything between locks
 * (gravity, DAS, rotation kicks) is client-side presentation; the server only
 * checks that the final resting position is legal.
 */

export class InvalidLock extends Error {}

export function initialState(randomizer) {
    return {
        board: emptyBoard(),
        current: randomizer.piece(0),
        nextIndex: 1,
        hold: null,
        combo: -1,
        b2b: false,
        score: 0,
        lines: 0,
        pieces: 0,
        toppedOut: false,
    };
}

/** What `current` becomes if the player presses hold this turn. */
export function afterHold(state, randomizer) {
    if (state.hold === null) {
        return { current: randomizer.piece(state.nextIndex), hold: state.current, nextIndex: state.nextIndex + 1 };
    }
    return { current: state.hold, hold: state.current, nextIndex: state.nextIndex };
}

/**
 * Applies one lock.
 *
 * @param lock { piece, rotation, x, y, held, garbage: [{ lines, hole }] }
 *   `garbage` is incoming garbage the client inserted right after this lock;
 *   it is only allowed on locks that clear no lines.
 */
export function applyLock(state, randomizer, lock) {
    if (state.toppedOut) throw new InvalidLock('Player has already topped out.');

    let { current, hold, nextIndex } = state;
    if (lock.held) {
        ({ current, hold, nextIndex } = afterHold(state, randomizer));
    }
    if (lock.piece !== current) {
        throw new InvalidLock(`Expected piece ${current}, got ${lock.piece}.`);
    }
    if (!Number.isInteger(lock.rotation) || lock.rotation < 0 || lock.rotation > 3) {
        throw new InvalidLock('Invalid rotation.');
    }
    if (!fits(state.board, lock.piece, lock.rotation, lock.x, lock.y)) {
        throw new InvalidLock('Piece overlaps the stack or the walls.');
    }
    if (!isGrounded(state.board, lock.piece, lock.rotation, lock.x, lock.y)) {
        throw new InvalidLock('Piece is floating.');
    }

    const placed = place(state.board, lock.piece, lock.rotation, lock.x, lock.y);
    const { board: afterClear, cleared } = clearLines(placed);
    const perfectClear = cleared.length > 0 && isEmpty(afterClear);
    const scored = scoreLock(cleared.length, state.combo, state.b2b, perfectClear);

    const garbage = lock.garbage ?? [];
    if (cleared.length > 0 && garbage.length > 0) {
        throw new InvalidLock('Garbage can only rise on a lock that clears no lines.');
    }

    let board = afterClear;
    let toppedOut = lockedOut(lock.piece, lock.rotation, lock.x, lock.y);
    for (const batch of garbage) {
        const result = addGarbage(board, batch.lines, batch.hole);
        board = result.board;
        toppedOut ||= result.overflow;
    }

    const next = randomizer.piece(nextIndex);
    toppedOut ||= !spawnFits(board, next);

    return {
        state: {
            board,
            current: next,
            nextIndex: nextIndex + 1,
            hold,
            combo: scored.combo,
            b2b: scored.b2b,
            score: state.score + scored.points,
            lines: state.lines + cleared.length,
            pieces: state.pieces + 1,
            toppedOut,
        },
        cleared,
        attack: scored.attack,
        points: scored.points,
    };
}

/** The next `count` pieces after the current one. */
export function preview(state, randomizer, count = 5) {
    return Array.from({ length: count }, (_, i) => randomizer.piece(state.nextIndex + i));
}
