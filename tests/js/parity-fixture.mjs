// Builds the cross-language parity fixture: piece sequences, SRS kick tables, the rule constants both
// sides hard-code, and whole bot games (with holds, combos and incoming garbage) and the expected
// state after every lock. tests/Unit/EngineParityTest.php replays it through the PHP engine.
//
//   node tests/js/parity-fixture.mjs           write tests/Fixtures/engine-parity.json
//   import { buildFixture } from ...           (used by engine.test.mjs to check it is current)

import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { Randomizer } from '../../resources/js/engine/randomizer.js';
import { applyLock, initialState } from '../../resources/js/engine/simulation.js';
import { serialize } from '../../resources/js/engine/board.js';
import { DIFFICULTIES, chooseMove } from '../../resources/js/engine/bot.js';
import * as constants from '../../resources/js/engine/constants.js';

const { PIECE_TYPES, kicksFor } = constants;

export const FIXTURE_PATH = fileURLToPath(new URL('../Fixtures/engine-parity.json', import.meta.url));

/** Small deterministic PRNG for the fixture's own choices (mistakes, garbage). */
function mulberry32(seed) {
    return () => {
        seed = (seed + 0x6d2b79f5) | 0;
        let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function playGame(seed, maxLocks) {
    const randomizer = new Randomizer(seed);
    const random = mulberry32(seed ^ 0x5eed);
    let state = initialState(randomizer);
    const locks = [];

    while (!state.toppedOut && locks.length < maxLocks) {
        const move = chooseMove(state, randomizer, { mistakeRate: 0.12, random });
        if (!move) break;
        const lock = { piece: move.piece, rotation: move.rotation, x: move.x, y: move.y, held: move.held, garbage: [] };

        let result = applyLock(state, randomizer, lock);
        if (result.cleared.length === 0 && random() < 0.15) {
            lock.garbage = [{ lines: 1 + Math.floor(random() * 3), hole: Math.floor(random() * 10) }];
            result = applyLock(state, randomizer, lock);
        }
        state = result.state;

        locks.push({
            lock,
            expect: {
                board: serialize(state.board),
                current: state.current,
                nextIndex: state.nextIndex,
                hold: state.hold,
                combo: state.combo,
                b2b: state.b2b,
                score: state.score,
                lines: state.lines,
                pieces: state.pieces,
                toppedOut: state.toppedOut,
                cleared: result.cleared,
                attack: result.attack,
            },
        });
    }
    return { seed, locks };
}

export function buildFixture() {
    const seeds = [0, 1, 42, 987654321, 4294967295];
    return {
        sequences: seeds.map((seed) => {
            const randomizer = new Randomizer(seed);
            return { seed, pieces: Array.from({ length: 70 }, (_, i) => randomizer.piece(i)).join('') };
        }),
        games: [1, 42, 2024, 31337].map((seed) => playGame(seed, 250)),
        // Constants the client and server each define. The games above exercise most of them; listing them
        // catches a one-sided change before it shows up as resyncs.
        rules: {
            garbageGraceMs: constants.GARBAGE_GRACE_MS,
            linePoints: constants.LINE_POINTS,
            lineAttack: constants.LINE_ATTACK,
            comboAttack: constants.COMBO_ATTACK,
            comboPoints: constants.COMBO_POINTS,
            b2bAttackBonus: constants.B2B_ATTACK_BONUS,
            perfectClearAttack: constants.PERFECT_CLEAR_ATTACK,
            perfectClearPoints: constants.PERFECT_CLEAR_POINTS,
            botLevels: DIFFICULTIES,
        },
        // SRS kicks for every turn between adjacent rotation states, keyed "I0>1".
        kicks: Object.fromEntries(
            PIECE_TYPES.flatMap((type) =>
                [0, 1, 2, 3].flatMap((from) => [(from + 1) % 4, (from + 3) % 4].map((to) => [`${type}${from}>${to}`, kicksFor(type, from, to)])),
            ),
        ),
    };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const fixture = buildFixture();
    writeFileSync(FIXTURE_PATH, JSON.stringify(fixture));
    const locks = fixture.games.reduce((n, g) => n + g.locks.length, 0);
    console.log(`Wrote ${FIXTURE_PATH}: ${fixture.sequences.length} sequences, ${fixture.games.length} games, ${locks} locks.`);
}
