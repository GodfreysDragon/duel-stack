import { HEIGHT, SPAWN_Y, WIDTH, EMPTY } from './constants.js';
import { clearLines, dropY, fits, place } from './board.js';
import { afterHold } from './simulation.js';

// Weights from Yiyuan Lee's "Tetris AI – The (Near) Perfect Bot" (El-Tetris style features).
const WEIGHTS = { height: -0.510066, lines: 0.760666, holes: -0.35663, bumpiness: -0.184483 };

export const DIFFICULTIES = {
    easy: { piecesPerSecond: 0.9, mistakeRate: 0.25 },
    medium: { piecesPerSecond: 1.6, mistakeRate: 0.08 },
    hard: { piecesPerSecond: 2.6, mistakeRate: 0 },
};

function columnHeights(board) {
    const heights = [];
    for (let x = 0; x < WIDTH; x++) {
        let y = 0;
        while (y < HEIGHT && board[y][x] === EMPTY) y++;
        heights.push(HEIGHT - y);
    }
    return heights;
}

export function evaluate(board, linesCleared) {
    const heights = columnHeights(board);
    let holes = 0;
    for (let x = 0; x < WIDTH; x++) {
        for (let y = HEIGHT - heights[x] + 1; y < HEIGHT; y++) {
            if (board[y][x] === EMPTY) holes++;
        }
    }
    let bumpiness = 0;
    for (let x = 0; x < WIDTH - 1; x++) bumpiness += Math.abs(heights[x] - heights[x + 1]);
    const aggregate = heights.reduce((a, b) => a + b, 0);

    return (
        WEIGHTS.height * aggregate +
        WEIGHTS.lines * linesCleared +
        WEIGHTS.holes * holes +
        WEIGHTS.bumpiness * bumpiness
    );
}

/** Every legal hard-drop placement of `type`: [{ rotation, x, y, score }]. */
export function placements(board, type) {
    const seen = new Set();
    const result = [];
    for (let rotation = 0; rotation < 4; rotation++) {
        for (let x = -2; x < WIDTH; x++) {
            if (!fits(board, type, rotation, x, SPAWN_Y)) continue;
            const y = dropY(board, type, rotation, x, SPAWN_Y);
            const placed = place(board, type, rotation, x, y);
            const key = placed.join('');
            if (seen.has(key)) continue; // O piece and symmetric rotations
            seen.add(key);
            const { board: after, cleared } = clearLines(placed);
            result.push({ rotation, x, y, score: evaluate(after, cleared.length) });
        }
    }
    return result;
}

/**
 * Chooses a move for the current turn, considering both the current piece and
 * the hold swap. Returns { held, piece, rotation, x, y } or null if nothing fits.
 */
export function chooseMove(state, randomizer, { canHold = true, mistakeRate = 0, random = Math.random } = {}) {
    const options = placements(state.board, state.current).map((p) => ({ ...p, held: false, piece: state.current }));
    if (canHold) {
        const swapped = afterHold(state, randomizer);
        if (swapped.current !== state.current) {
            options.push(...placements(state.board, swapped.current).map((p) => ({ ...p, held: true, piece: swapped.current })));
        }
    }
    if (options.length === 0) return null;

    options.sort((a, b) => b.score - a.score);
    // Weaker bots sometimes settle for a lesser move.
    const pick = random() < mistakeRate ? options[Math.min(options.length - 1, 1 + Math.floor(random() * 4))] : options[0];
    return { held: pick.held, piece: pick.piece, rotation: pick.rotation, x: pick.x, y: pick.y };
}
