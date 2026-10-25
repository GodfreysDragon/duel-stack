import { test } from 'node:test';
import assert from 'node:assert/strict';
import { LockSync } from '../../resources/js/engine/lockSync.js';
import { emptyBoard, serialize } from '../../resources/js/engine/board.js';

const BOARD = serialize(emptyBoard());
const OTHER_BOARD = 'G' + BOARD.slice(1);

/**
 * A LockSync against a fake server whose answers the test releases one at a time, in whatever order the race needs.
 */
function setup({ pendingGarbage = [] } = {}) {
    const requests = [];
    const events = [];
    const game = {
        pendingGarbage,
        receiveGarbage(batch) {
            this.pendingGarbage.push(batch);
        },
    };
    const sync = new LockSync({
        post: (body) => new Promise((resolve, reject) => requests.push({ body, resolve, reject })),
        game: () => game,
        onResync: (state, pending, reason) => events.push(['resync', state, pending, reason]),
        onMatchOver: () => events.push(['matchOver']),
        onMatchClosed: () => events.push(['matchClosed']),
        wait: async () => events.push(['wait']),
    });

    /** Answers the oldest request still open and lets the client react. */
    const answer = async (response) => {
        requests.shift().resolve({ state: { board: BOARD }, sent: null, match_over: false, garbage_through: 0, pending_garbage: [], ...response });
        await flush();
    };
    const reject = async (status, data = {}) => {
        requests.shift().reject(Object.assign(new Error('rejected'), { status, data }));
        await flush();
    };

    return { sync, game, requests, events, answer, reject };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

/** A lock the local game made, and the outcome it predicted. */
function lock(pieces, { attack = 0, sent = 0, garbageIds = [], board = emptyBoard() } = {}) {
    return [
        { piece: 'I', rotation: 0, x: 3, y: 20, held: false, garbageIds },
        { state: { pieces, board }, attack, sent, cleared: [] },
    ];
}

test('locks go to the server one at a time, in order', async () => {
    const { sync, requests, answer } = setup();
    sync.push(...lock(1));
    sync.push(...lock(2));

    assert.equal(requests.length, 1, 'the second lock waits for the first answer');
    assert.equal(requests[0].body.piece_number, 0);
    await answer();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].body.piece_number, 1);
});

test("a confirmed lock takes the server's pending garbage without a resync", async () => {
    const { sync, game, events, answer } = setup({ pendingGarbage: [{ id: 1, lines: 2, hole: 0 }] });
    sync.push(...lock(1));
    await answer({ garbage_through: 1, pending_garbage: [{ id: 1, lines: 1, hole: 0 }] });

    assert.deepEqual(events, []);
    assert.deepEqual(game.pendingGarbage, [{ id: 1, lines: 1, hole: 0 }], 'the server cancelled more than the client knew');
});

test('a board the server disagrees with is resynced', async () => {
    const { sync, events, answer } = setup();
    sync.push(...lock(1));
    await answer({ state: { board: OTHER_BOARD } });

    assert.equal(events.length, 1);
    assert.equal(events[0][0], 'resync');
    assert.equal(events[0][3], 'board differs after piece #0');
});

test('a rejected lock drops the queue and resyncs to the state the server sent', async () => {
    const { sync, game, requests, events, reject } = setup({ pendingGarbage: [{ id: 7, lines: 2, hole: 3 }] });
    sync.push(...lock(1));
    sync.push(...lock(2));
    await reject(409, { message: 'Piece is floating.', state: { board: OTHER_BOARD }, garbage_through: 5, pending_garbage: [] });

    assert.equal(requests.length, 0, 'the lock queued behind it is dropped');
    assert.equal(events[0][3], 'lock rejected: Piece is floating.');
    assert.deepEqual(events[0][2], [{ id: 7, lines: 2, hole: 3 }], 'a batch newer than the server knew of is kept');
    assert.equal(game.pendingGarbage.length, 1);
});

test('a lock for a match that already ended asks for the result instead', async () => {
    const { sync, events, reject } = setup();
    sync.push(...lock(1));
    await reject(409, { message: 'The match is over.' });

    assert.deepEqual(events, [['matchClosed']]);
});

test('network trouble keeps the lock and sends it again', async () => {
    const { sync, requests, events, reject } = setup();
    sync.push(...lock(1));
    await reject(500);

    assert.deepEqual(events, [['wait']]);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].body.piece_number, 0);
});

test('topping out waits for the result', async () => {
    const { sync, events, answer } = setup();
    sync.push(...lock(1));
    await answer({ match_over: true });

    assert.deepEqual(events, [['matchOver']]);
});

test('a late broadcast for a batch the server already accounted for is dropped', async () => {
    // The lock's attack cancelled batch 3 outright before its broadcast reached this client.
    const { sync, game, answer } = setup();
    sync.push(...lock(1, { attack: 2 }));
    await answer({ garbage_through: 3, pending_garbage: [] });

    assert.equal(sync.receive({ id: 3, lines: 2, hole: 0 }), false);
    assert.deepEqual(game.pendingGarbage, []);
    assert.equal(sync.receive({ id: 4, lines: 1, hole: 0 }), true, 'a newer batch still arrives');
    assert.deepEqual(game.pendingGarbage, [{ id: 4, lines: 1, hole: 0 }]);
});

test('garbage that arrives while an attack is unconfirmed waits for the server', async () => {
    const { sync, game, answer } = setup();
    sync.push(...lock(1, { attack: 4 }));

    sync.receive({ id: 3, lines: 2, hole: 0 }); // the attack cancels this one on the server
    sync.receive({ id: 5, lines: 1, hole: 6 }); // sent after the server answered
    assert.deepEqual(game.pendingGarbage, [], 'not taken in while the attack is in flight');

    await answer({ garbage_through: 4, pending_garbage: [] });
    assert.deepEqual(game.pendingGarbage, [{ id: 5, lines: 1, hole: 6 }]);
});

test('garbage arriving with no attack in flight is taken in straight away', () => {
    const { sync, game } = setup();
    sync.push(...lock(1));

    sync.receive({ id: 2, lines: 1, hole: 0 });
    assert.deepEqual(game.pendingGarbage, [{ id: 2, lines: 1, hole: 0 }]);
});

test('sent counts what the server confirmed plus what the locks in flight should send', async () => {
    const { sync, answer } = setup();
    sync.confirmedSent = 3;
    sync.push(...lock(1, { sent: 2 }));
    sync.push(...lock(2, { sent: 1 }));
    assert.equal(sync.sent, 6);

    await answer({ sent: { id: 9, lines: 2, hole: 0 } });
    assert.equal(sync.sent, 6, 'the confirmed 2 replace the predicted 2');
});

test('nothing more is sent once the match is over', async () => {
    const { sync, requests, answer } = setup();
    sync.push(...lock(1));
    sync.push(...lock(2));
    sync.stop();
    await answer();

    assert.equal(requests.length, 0);
});
