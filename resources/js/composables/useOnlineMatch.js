import { onBeforeUnmount, reactive, ref, shallowRef } from 'vue';
import { api, ApiError } from '../lib/api';
import { echo } from '../lib/echo';
import { session } from '../lib/session';
import { Game } from '../engine/game.js';
import { deserialize, emptyBoard } from '../engine/board.js';
import { LockSync } from '../engine/lockSync.js';
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
 * The local Game runs ahead optimistically; LockSync sends its locks in order and
 * resyncs the game when the server disagrees or rejects one (409).
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
    const sync = new LockSync({
        post: (body) => api('POST', `${url}/locks`, body),
        game: () => game.value,
        onResync: resync,
        onMatchOver: waitForResult,
        onMatchClosed: () => refreshResult().catch(() => {}),
        onProgress: () => (stats.sent = sync.sent),
    });
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
            sync.garbageThrough = data.you.garbage_through;
            if (data.you.state.pieces > 0 || data.you.pending_garbage.length) {
                g.resync(toEngineState(data.you.state), data.you.pending_garbage);
            }
            game.value = g;
            sync.confirmedSent = me.value.garbage_sent;
            Object.assign(stats, { score: data.you.state.score, lines: data.you.state.lines, sent: sync.sent });

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
                if (e.from_user_id !== session.user.id && sync.receive(e.batch)) sfx.incoming();
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
        stats.score = outcome.state.score;
        stats.lines = outcome.state.lines;
        if (!autopilot) sfx.lock(outcome.cleared.length);
        sync.push(lock, outcome);
        stats.sent = sync.sent;
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
        sync.stop();
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
