import { onBeforeUnmount, reactive, ref, shallowRef } from 'vue';
import { api, ApiError } from '../lib/api';
import { echo } from '../lib/echo';
import { session } from '../lib/session';
import { Game } from '../engine/game.js';
import { deserialize, emptyBoard, serialize } from '../engine/board.js';
import { mergePendingGarbage } from '../engine/simulation.js';
import { chooseMove } from '../engine/bot.js';
import { createLockPlayback } from '../lib/lockPlayback';
import { settings } from '../lib/settings';
import { sfx } from '../lib/sound';

const ABANDON_AFTER_MS = 20000;
const WHISPER_INTERVAL_MS = 80;
// Sent from the render loop, which only runs while the tab is visible, so heartbeats stop when the player leaves.
const HEARTBEAT_INTERVAL_MS = 5000;
const RESULT_FALLBACK_MS = 3000;

const toEngineState = (state) => ({ ...state, board: deserialize(state.board) });

/**
 * Client side of a ranked match.
 *
 * The local Game runs ahead optimistically; every lock goes into a queue that
 * is POSTed in order. The server answers with its own state: if it disagrees,
 * or rejects a lock (409), the game resyncs to the server's state.
 * Opponent boards and garbage arrive over the match's presence channel, and the
 * opponent's falling piece arrives as a client whisper. House bots don't whisper, so their
 * locks are played back as a falling piece, a fraction of a second behind the server.
 */
export function useOnlineMatch(matchId, { autopilot = false } = {}) {
    const status = ref('loading'); // loading | countdown | playing | waiting | finished | spectating | error
    const error = ref(null);
    const match = ref(null);
    const result = ref(null);
    const countdown = ref(0);
    const me = ref(null);
    const opponent = ref(null);
    const game = shallowRef(null);
    const stats = reactive({ score: 0, lines: 0, sent: 0, resyncs: 0 });
    const opp = reactive({
        board: emptyBoard(),
        active: null,
        score: 0,
        lines: 0,
        sent: 0,
        toppedOut: false,
        online: false,
        lastHeardAt: Date.now(),
    });
    const now = ref(Date.now());

    let clockOffset = 0;
    let channel = null;
    let queue = []; // [{ body, sent, attack }]: locks waiting to be POSTed, with the garbage each sends and its attack
    let sending = false;
    let confirmedSent = 0;
    // The newest garbage batch the server's last pending list accounted for: a later garbage.sent broadcast for it is
    // stale (the batch may have been cancelled outright meanwhile).
    let garbageThrough = 0;
    // Batches that arrived while a lock that attacks was unconfirmed: the server may have used that attack to cancel
    // them, so they wait for its pending list (garbage has a grace period, so this is safe).
    let deferredGarbage = [];
    const expectedBoards = new Map();
    let lastWhisper = 0;
    let lastWhispered = '';
    let lastHeartbeat = 0;
    const botPlayback = createLockPlayback(opp, showOpponentBoard);
    const timers = [];

    const url = `/api/matches/${matchId}`;
    const serverNow = () => Date.now() + clockOffset;

    async function load() {
        try {
            const data = await api('GET', url);
            clockOffset = Date.parse(data.server_time) - Date.now();
            match.value = data.match;
            me.value = data.match.players.find((p) => p.user.id === session.user?.id);
            opponent.value = data.match.players.find((p) => p.user.id !== session.user?.id);

            if (data.match.status === 'finished' || !data.you) {
                return finish(data.match);
            }

            Object.assign(opp, {
                board: deserialize(opponent.value.board),
                score: opponent.value.score,
                lines: opponent.value.lines,
                sent: opponent.value.garbage_sent,
                toppedOut: opponent.value.topped_out,
            });

            const g = new Game({ seed: data.match.seed, onLock, onTopOut: waitForResult, handling: settings.handling });
            garbageThrough = data.you.garbage_through;
            if (data.you.state.pieces > 0 || data.you.pending_garbage.length) {
                g.resync(toEngineState(data.you.state), data.you.pending_garbage);
            }
            game.value = g;
            confirmedSent = me.value.garbage_sent;
            Object.assign(stats, { score: data.you.state.score, lines: data.you.state.lines, sent: confirmedSent });

            subscribe();
            startCountdown(Date.parse(data.match.starts_at));
            timers.push(setInterval(() => (now.value = Date.now()), 1000));
            if (autopilot) startAutopilot();
        } catch (e) {
            // A live match only loads for its players; anyone else can watch it.
            if (e instanceof ApiError && e.status === 403) {
                status.value = 'spectating';
                return;
            }
            error.value = e.message;
            status.value = 'error';
        }
    }

    function startCountdown(startsAt) {
        status.value = 'countdown';
        const tick = () => {
            const remaining = startsAt - serverNow();
            const seconds = Math.max(0, Math.ceil(remaining / 1000));
            if (seconds !== countdown.value && seconds > 0) sfx.countdownTick();
            countdown.value = seconds;
            if (remaining <= 0 && status.value === 'countdown') {
                sfx.countdownTick(true);
                clearInterval(timer);
                status.value = 'playing';
                game.value.start();
            }
        };
        const timer = setInterval(tick, 50);
        timers.push(timer);
        tick();
    }

    function subscribe() {
        const opponentId = opponent.value.user.id;
        // House bots play from the server and never join the presence channel.
        const isBot = opponent.value.user.is_bot;
        opp.online = isBot;
        channel = echo
            .join(`match.${matchId}`)
            .here((users) => (opp.online = isBot || users.some((u) => u.id === opponentId)))
            .joining((user) => {
                if (user.id === opponentId) Object.assign(opp, { online: true, lastHeardAt: Date.now() });
            })
            .leaving((user) => {
                if (user.id === opponentId) Object.assign(opp, { online: false, active: null });
            })
            .listen('.board.updated', (e) => {
                if (e.user_id !== opponentId) return;
                if (isBot && e.lock) botPlayback.queue(e);
                else showOpponentBoard(e);
            })
            .listen('.garbage.sent', (e) => {
                if (e.from_user_id === session.user.id || e.batch.id <= garbageThrough) return;
                if (queue.some((item) => item.attack > 0)) deferredGarbage.push(e.batch);
                else game.value?.receiveGarbage(e.batch);
                sfx.incoming();
            })
            .listen('.match.ended', (e) => finish(e.match))
            .listenForWhisper('piece', (piece) => {
                opp.active = piece;
                opp.lastHeardAt = Date.now();
            });
    }

    function showOpponentBoard(e) {
        Object.assign(opp, {
            board: deserialize(e.board),
            score: e.score,
            lines: e.lines,
            sent: e.garbage_sent,
            toppedOut: e.topped_out,
            active: null,
            lastHeardAt: Date.now(),
        });
    }

    // ---- locks ----------------------------------------------------------------

    function onLock(lock, outcome) {
        const pieceNumber = outcome.state.pieces - 1;
        expectedBoards.set(pieceNumber, serialize(outcome.state.board));
        stats.score = outcome.state.score;
        stats.lines = outcome.state.lines;
        if (!autopilot) sfx.lock(outcome.cleared.length);
        queue.push({
            body: {
                piece_number: pieceNumber,
                piece: lock.piece,
                rotation: lock.rotation,
                x: lock.x,
                y: lock.y,
                held: lock.held,
                garbage_ids: lock.garbageIds,
            },
            sent: outcome.sent,
            attack: outcome.attack,
        });
        updateSent();
        pump();
    }

    /** Garbage the server confirmed, plus what the locks still in flight should send. */
    function updateSent() {
        stats.sent = confirmedSent + queue.reduce((sum, item) => sum + item.sent, 0);
    }

    async function pump() {
        if (sending || queue.length === 0 || status.value === 'finished') return;
        sending = true;
        const item = queue[0];
        try {
            const response = await api('POST', `${url}/locks`, item.body);
            queue.shift();
            confirmedSent += response.sent?.lines ?? 0;
            updateSent();
            const expected = expectedBoards.get(item.body.piece_number);
            expectedBoards.delete(item.body.piece_number);
            if (queue.length === 0 && !response.match_over) {
                garbageThrough = Math.max(garbageThrough, response.garbage_through);
                const pending = mergePendingGarbage(response.pending_garbage, [...game.value.pendingGarbage, ...deferredGarbage], garbageThrough);
                deferredGarbage = [];
                if (response.state.board !== expected) resync(response.state, pending, `board differs after piece #${item.body.piece_number}`);
                else game.value.pendingGarbage = pending;
            }
            if (response.match_over) waitForResult();
        } catch (e) {
            if (e instanceof ApiError && e.status === 409) {
                queue = [];
                updateSent();
                expectedBoards.clear();
                if (e.data?.message === 'The match is over.') {
                    await refreshResult();
                } else if (e.data?.state) {
                    garbageThrough = Math.max(garbageThrough, e.data.garbage_through);
                    const pending = mergePendingGarbage(e.data.pending_garbage, [...game.value.pendingGarbage, ...deferredGarbage], garbageThrough);
                    deferredGarbage = [];
                    resync(e.data.state, pending, `lock rejected: ${e.data.message}`);
                }
            } else {
                // Network trouble: keep the queue and retry. A lock that did land
                // comes back as a 409 "expected piece #n" and triggers a resync.
                await new Promise((resolve) => setTimeout(resolve, 600));
            }
        } finally {
            sending = false;
            pump();
        }
    }

    function resync(state, pendingGarbage, reason) {
        stats.resyncs++;
        console.warn(`[match] resynced to the server: ${reason}`);
        game.value.resync(toEngineState(state), pendingGarbage);
        stats.score = state.score;
        stats.lines = state.lines;
    }

    /**
     * After topping out, the result arrives as match.ended, which can beat the final lock's HTTP response
     * (so never step back from 'finished'). If the broadcast is lost, ask the server instead.
     */
    function waitForResult() {
        if (status.value === 'finished') return;
        status.value = 'waiting';
        timers.push(
            setTimeout(() => {
                if (status.value === 'waiting') refreshResult().catch(() => {});
            }, RESULT_FALLBACK_MS),
        );
    }

    async function refreshResult() {
        const data = await api('GET', url);
        if (data.match.status === 'finished') finish(data.match);
    }

    function finish(finalMatch) {
        const wasLive = status.value !== 'loading' && status.value !== 'finished';
        if (wasLive) finalMatch.winner_id === session.user?.id ? sfx.win() : sfx.lose();
        game.value?.stop();
        botPlayback.flush();
        match.value = finalMatch;
        result.value = finalMatch;
        status.value = 'finished';
        queue = [];
        session.activeMatchId = null;
    }

    // ---- actions ----------------------------------------------------------------

    async function forfeit() {
        const data = await api('POST', `${url}/forfeit`);
        finish(data.match);
    }

    async function claim() {
        try {
            const data = await api('POST', `${url}/claim`);
            finish(data.match);
        } catch (e) {
            error.value = e.message;
        }
    }

    const opponentIdleMs = () => now.value - opp.lastHeardAt;
    const canClaim = () => status.value !== 'finished' && !opp.toppedOut && opponentIdleMs() > ABANDON_AFTER_MS;

    function startAutopilot() {
        timers.push(
            setInterval(() => {
                const g = game.value;
                if (!g?.running) return;
                const move = chooseMove(g.state, g.randomizer, { canHold: !g.heldThisTurn });
                if (move) g.placeDirect(move);
            }, 260),
        );
    }

    // ---- rendering ----------------------------------------------------------------

    function frame(dt) {
        const g = game.value;
        if (!g) return { me: { board: emptyBoard() }, opponent: opp };
        g.update(dt);
        botPlayback.frame();

        if (g.running && performance.now() - lastHeartbeat > HEARTBEAT_INTERVAL_MS) {
            lastHeartbeat = performance.now();
            api('POST', `${url}/heartbeat`).catch(() => {});
        }

        if (channel && g.running && performance.now() - lastWhisper > WHISPER_INTERVAL_MS) {
            const key = `${g.active.type}${g.active.rotation}:${g.active.x}:${g.active.y}`;
            if (key !== lastWhispered) {
                channel.whisper('piece', { ...g.active });
                lastWhispered = key;
                lastWhisper = performance.now();
            }
        }

        return {
            me: {
                board: g.state.board,
                active: g.running ? g.active : null,
                ghostY: g.running ? g.ghostY : undefined,
                hold: g.state.hold,
                holdLocked: g.heldThisTurn,
                next: g.preview,
                pending: g.pendingLines,
                clear: g.lastClear,
                toppedOut: g.state.toppedOut,
            },
            opponent: opp,
        };
    }

    load();

    onBeforeUnmount(() => {
        timers.forEach(clearInterval);
        game.value?.stop();
        echo.leave(`match.${matchId}`);
    });

    return { status, error, match, result, countdown, me, opponent, opp, stats, game, frame, forfeit, claim, canClaim, opponentIdleMs };
}
