import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Randomizer } from '../../resources/js/engine/randomizer.js';
import { applyLock, initialState, InvalidLock } from '../../resources/js/engine/simulation.js';
import { addGarbage, deserialize, emptyBoard, scoreLock, serialize } from '../../resources/js/engine/board.js';
import { Game } from '../../resources/js/engine/game.js';
import { chooseMove } from '../../resources/js/engine/bot.js';
import { pieceAlongLock } from '../../resources/js/engine/playback.js';
import { PIECE_TYPES } from '../../resources/js/engine/constants.js';
import { buildFixture, FIXTURE_PATH } from './parity-fixture.mjs';

test('the committed parity fixture matches what the JS engine produces today', () => {
    const committed = JSON.parse(readFileSync(FIXTURE_PATH, 'utf8'));
    assert.deepEqual(buildFixture(), committed, 'Engine changed: run `npm run fixture` and re-run the PHP parity test.');
});

test('every bag of seven contains each piece exactly once', () => {
    const randomizer = new Randomizer(123);
    for (let bag = 0; bag < 50; bag++) {
        const pieces = Array.from({ length: 7 }, (_, i) => randomizer.piece(bag * 7 + i));
        assert.deepEqual([...pieces].sort(), PIECE_TYPES);
    }
});

test('the same seed always deals the same sequence', () => {
    const a = new Randomizer(99);
    const b = new Randomizer(99);
    assert.equal(Array.from({ length: 30 }, (_, i) => a.piece(i)).join(''), Array.from({ length: 30 }, (_, i) => b.piece(i)).join(''));
});

test('scoring tables: back-to-back tetris, combos and perfect clears', () => {
    assert.deepEqual(scoreLock(4, -1, true, false), { points: 1200, attack: 5, combo: 0, b2b: true });
    assert.deepEqual(scoreLock(1, 3, false, false), { points: 300, attack: 2, combo: 4, b2b: false });
    assert.deepEqual(scoreLock(1, -1, false, true), { points: 2100, attack: 4, combo: 0, b2b: false });
    assert.deepEqual(scoreLock(0, 5, true, false), { points: 0, attack: 0, combo: -1, b2b: true });
});

test('garbage that pushes blocks off the top reports an overflow', () => {
    const board = emptyBoard();
    board[0] = 'I.........';
    assert.equal(addGarbage(board, 1, 3).overflow, true);
    assert.equal(addGarbage(emptyBoard(), 4, 3).overflow, false);
});

test('a floating piece is rejected', () => {
    const randomizer = new Randomizer(0);
    assert.throws(
        () => applyLock(initialState(randomizer), randomizer, { piece: 'I', rotation: 0, x: 3, y: 5, held: false, garbage: [] }),
        InvalidLock,
    );
});

function startedGame(seed, onLock = () => {}) {
    const game = new Game({ seed, onLock });
    game.start();
    return game;
}

test('a lock after a hold is reported so the server can validate it from the turn start', () => {
    const locks = [];
    const game = startedGame(0, (lock) => locks.push(lock));
    game.press('hold');
    game.press('hardDrop');

    const [lock] = locks;
    assert.equal(lock.held, true);
    const randomizer = new Randomizer(0);
    const { state } = applyLock(initialState(randomizer), randomizer, { ...lock, garbage: [] });
    assert.equal(serialize(state.board), serialize(game.state.board));
    assert.equal(state.hold, 'I');
});

test('hold can only be used once per piece', () => {
    const game = startedGame(0);
    game.press('hold');
    const active = game.active.type;
    game.press('hold');
    assert.equal(game.active.type, active);
});

test('pending garbage rises on the next lock that clears nothing, and its ids are reported', () => {
    const locks = [];
    const game = startedGame(0, (lock) => locks.push(lock));
    game.receiveGarbage({ id: 7, lines: 2, hole: 0 });
    game.receiveGarbage({ id: 7, lines: 2, hole: 0 }); // duplicates (e.g. after a resync) are ignored
    game.press('hardDrop');

    assert.deepEqual(locks[0].garbageIds, [7]);
    assert.equal(game.pendingLines, 0);
    assert.equal(game.state.board[21], '.GGGGGGGGG');
    assert.equal(game.state.board[20], '.GGGGGGGGG');
});

test('gravity pulls the piece down and the lock delay then locks it', () => {
    const locks = [];
    const game = startedGame(0, (lock) => locks.push(lock));
    const startY = game.active.y;
    game.update(1000);
    assert.equal(game.active.y, startY + 1);

    for (let i = 0; i < 40 && locks.length === 0; i++) game.update(1000);
    assert.equal(locks.length, 1);
    assert.equal(locks[0].y, 20);
});

test('moving sideways with DAS slides the piece to the wall', () => {
    const game = startedGame(0);
    game.press('left');
    for (let i = 0; i < 20; i++) game.update(16);
    assert.equal(game.active.x, 0); // seed 0 starts with an I, whose leftmost cell is at x
});

test('resync replaces the local state with the server one', () => {
    const game = startedGame(0);
    const board = emptyBoard();
    board[21] = 'GGGGGGGGG.';
    game.resync({ ...initialState(new Randomizer(0)), board, pieces: 5, current: 'T', nextIndex: 6 }, [{ id: 3, lines: 1, hole: 2 }]);
    assert.equal(game.active.type, 'T');
    assert.equal(game.pendingLines, 1);
    assert.deepEqual(deserialize(serialize(game.state.board)), board);
});

test('the hard bot survives 300 pieces on a clean board', () => {
    const randomizer = new Randomizer(2026);
    let state = initialState(randomizer);
    for (let i = 0; i < 300; i++) {
        const move = chooseMove(state, randomizer);
        state = applyLock(state, randomizer, { ...move, garbage: [] }).state;
        assert.equal(state.toppedOut, false, `topped out at piece ${i}`);
    }
    assert.ok(state.lines > 100);
});

test('a played-back bot lock spawns, turns and shifts at the top, then falls to where it locked', () => {
    const board = emptyBoard();
    const lock = { piece: 'T', rotation: 3, x: 6, y: 19 };
    const path = Array.from({ length: 101 }, (_, i) => pieceAlongLock(board, lock, i / 100));

    assert.deepEqual(path[0], { type: 'T', rotation: 0, x: 3, y: 1 });
    assert.deepEqual(path[100], { type: 'T', rotation: 3, x: 6, y: 19 });
    // One step at a time: never more than one column, one turn or one row between frames, and never back up.
    for (let i = 1; i < path.length; i++) {
        const [a, b] = [path[i - 1], path[i]];
        assert.ok(Math.abs(b.x - a.x) <= 1 && b.y >= a.y, `jump between ${JSON.stringify(a)} and ${JSON.stringify(b)}`);
    }
    assert.equal(new Set(path.map((p) => p.rotation)).size, 2, 'turns once, the short way round');
});
