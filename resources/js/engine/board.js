import {
    COMBO_ATTACK,
    COMBO_POINTS,
    B2B_ATTACK_BONUS,
    EMPTY,
    GARBAGE,
    HEIGHT,
    HIDDEN_ROWS,
    LINE_ATTACK,
    LINE_POINTS,
    PERFECT_CLEAR_ATTACK,
    PERFECT_CLEAR_POINTS,
    SHAPES,
    SPAWN_X,
    SPAWN_Y,
    WIDTH,
} from './constants.js';

/**
 * Pure board helpers. A board is an array of HEIGHT strings, each WIDTH chars
 * ('.' empty, a piece letter, or 'G' garbage). The server stores the same
 * thing as one 220-char string (see serialize/deserialize).
 */

export function emptyBoard() {
    return Array.from({ length: HEIGHT }, () => EMPTY.repeat(WIDTH));
}

export function serialize(board) {
    return board.join('');
}

export function deserialize(str) {
    return Array.from({ length: HEIGHT }, (_, y) => str.slice(y * WIDTH, (y + 1) * WIDTH));
}

export function cellsOf(type, rotation, x, y) {
    return SHAPES[type][rotation].map(([cx, cy]) => [x + cx, y + cy]);
}

export function fits(board, type, rotation, x, y) {
    return cellsOf(type, rotation, x, y).every(
        ([cx, cy]) => cx >= 0 && cx < WIDTH && cy >= 0 && cy < HEIGHT && board[cy][cx] === EMPTY,
    );
}

export function isGrounded(board, type, rotation, x, y) {
    return !fits(board, type, rotation, x, y + 1);
}

export function dropY(board, type, rotation, x, y) {
    while (fits(board, type, rotation, x, y + 1)) y++;
    return y;
}

export function spawnFits(board, type) {
    return fits(board, type, 0, SPAWN_X, SPAWN_Y);
}

/** Writes the piece into a copy of the board. */
export function place(board, type, rotation, x, y) {
    const rows = board.map((row) => row.split(''));
    for (const [cx, cy] of cellsOf(type, rotation, x, y)) rows[cy][cx] = type;
    return rows.map((row) => row.join(''));
}

/** Removes full rows; returns { board, cleared: [rowIndex, ...] }. */
export function clearLines(board) {
    const cleared = [];
    const kept = [];
    board.forEach((row, y) => (row.includes(EMPTY) ? kept.push(row) : cleared.push(y)));
    const fresh = Array.from({ length: cleared.length }, () => EMPTY.repeat(WIDTH));
    return { board: [...fresh, ...kept], cleared };
}

/**
 * Pushes garbage rows in from the bottom. Returns { board, overflow }, where
 * overflow means a filled cell was pushed off the top (a top-out).
 */
export function addGarbage(board, lines, hole) {
    const overflow = board.slice(0, lines).some((row) => row !== EMPTY.repeat(WIDTH));
    const row = GARBAGE.repeat(hole) + EMPTY + GARBAGE.repeat(WIDTH - hole - 1);
    return { board: [...board.slice(lines), ...Array.from({ length: lines }, () => row)], overflow };
}

export function isEmpty(board) {
    return board.every((row) => row === EMPTY.repeat(WIDTH));
}

/** True when every cell of the locked piece sits in the hidden rows ("lock out"). */
export function lockedOut(type, rotation, x, y) {
    return cellsOf(type, rotation, x, y).every(([, cy]) => cy < HIDDEN_ROWS);
}

/**
 * Score and attack for one lock. `combo` is the combo counter *before* this
 * lock (-1 = none); `b2b` is whether the previous clear was a tetris.
 */
export function scoreLock(lines, combo, b2b, perfectClear) {
    if (lines === 0) {
        return { points: 0, attack: 0, combo: -1, b2b };
    }
    const nextCombo = combo + 1;
    const isTetris = lines === 4;
    const backToBack = isTetris && b2b;

    let points = LINE_POINTS[lines] + COMBO_POINTS * nextCombo;
    if (backToBack) points += LINE_POINTS[4] / 2;
    if (perfectClear) points += PERFECT_CLEAR_POINTS;

    let attack = LINE_ATTACK[lines] + COMBO_ATTACK[Math.min(nextCombo, COMBO_ATTACK.length - 1)];
    if (backToBack) attack += B2B_ATTACK_BONUS;
    if (perfectClear) attack += PERFECT_CLEAR_ATTACK;

    return { points, attack, combo: nextCombo, b2b: isTetris };
}
