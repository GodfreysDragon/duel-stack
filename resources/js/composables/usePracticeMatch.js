import { onBeforeUnmount, reactive, ref, shallowRef } from 'vue';
import { Game } from '../engine/game.js';
import { DIFFICULTIES, chooseMove } from '../engine/bot.js';
import { settings } from '../lib/settings';
import { sfx } from '../lib/sound';

/**
 * An offline match against the bot: two local Games on the same seed that
 * trade garbage directly. Nothing touches the server and nothing is ranked.
 */
export function usePracticeMatch() {
    const status = ref('idle'); // idle | countdown | playing | finished
    const countdown = ref(0);
    const winner = ref(null); // 'you' | 'bot'
    const game = shallowRef(null);
    const bot = shallowRef(null);
    const stats = reactive({ you: { score: 0, lines: 0, sent: 0 }, bot: { score: 0, lines: 0, sent: 0 } });
    const timers = [];
    let garbageId = 0;

    const clearTimers = () => timers.splice(0).forEach((t) => clearInterval(t));

    function start(difficulty = 'medium') {
        clearTimers();
        const seed = Math.floor(Math.random() * 4294967296);
        const settings = DIFFICULTIES[difficulty];

        const send = (target, attack) => {
            if (attack <= 0) return;
            target.receiveGarbage({ id: ++garbageId, lines: attack, hole: Math.floor(Math.random() * 10) });
            if (target === game.value) sfx.incoming();
        };
        const end = (who) => {
            if (status.value === 'finished') return;
            winner.value = who;
            who === 'you' ? sfx.win() : sfx.lose();
            status.value = 'finished';
            game.value.stop();
            bot.value.stop();
            clearTimers();
        };

        const you = new Game({
            seed,
            handling: settings.handling,
            onLock: (lock, outcome) => {
                Object.assign(stats.you, { score: outcome.state.score, lines: outcome.state.lines, sent: stats.you.sent + outcome.sent });
                sfx.lock(outcome.cleared.length);
                send(bot.value, outcome.sent);
            },
            onTopOut: () => end('bot'),
        });
        const opponent = new Game({
            seed,
            onLock: (lock, outcome) => {
                Object.assign(stats.bot, { score: outcome.state.score, lines: outcome.state.lines, sent: stats.bot.sent + outcome.sent });
                send(game.value, outcome.sent);
            },
            onTopOut: () => end('you'),
        });
        game.value = you;
        bot.value = opponent;
        winner.value = null;
        Object.assign(stats.you, { score: 0, lines: 0, sent: 0 });
        Object.assign(stats.bot, { score: 0, lines: 0, sent: 0 });

        status.value = 'countdown';
        countdown.value = 3;
        sfx.countdownTick();
        const countdownTimer = setInterval(() => {
            countdown.value--;
            if (countdown.value > 0) return sfx.countdownTick();
            sfx.countdownTick(true);
            clearInterval(countdownTimer);
            status.value = 'playing';
            you.start();
            opponent.start();
            timers.push(
                setInterval(() => {
                    if (!opponent.running) return;
                    const move = chooseMove(opponent.state, opponent.randomizer, {
                        canHold: !opponent.heldThisTurn,
                        mistakeRate: settings.mistakeRate,
                    });
                    if (move) opponent.placeDirect(move);
                }, 1000 / settings.piecesPerSecond),
            );
        }, 1000);
        timers.push(countdownTimer);
    }

    function view(g, panels) {
        return {
            board: g.state.board,
            active: g.running ? g.active : null,
            ghostY: panels && g.running ? g.ghostY : undefined,
            hold: g.state.hold,
            holdLocked: g.heldThisTurn,
            next: g.preview,
            pending: g.pendingLines,
            clear: g.lastClear,
            toppedOut: g.state.toppedOut,
        };
    }

    function frame(dt) {
        if (!game.value) return { me: { board: [] }, opponent: { board: [] } };
        game.value.update(dt);
        bot.value.update(dt);
        return { me: view(game.value, true), opponent: view(bot.value, false) };
    }

    onBeforeUnmount(() => {
        clearTimers();
        game.value?.stop();
        bot.value?.stop();
    });

    return { status, countdown, winner, stats, game, start, frame };
}
