<script setup>
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import GameLayout from '../components/GameLayout.vue';
import MatchActions from '../components/MatchActions.vue';
import PlayerHud from '../components/PlayerHud.vue';
import ResultCard from '../components/ResultCard.vue';
import { useKeyboard } from '../composables/useKeyboard';
import { useOnlineMatch } from '../composables/useOnlineMatch';
import { realtime } from '../lib/echo';
import { refreshSession, session } from '../lib/session';

const props = defineProps({ id: { type: String, required: true } });
const route = useRoute();
const router = useRouter();

// ?autopilot=1 lets the bot play this side of a real match, for the end-to-end test. It is compiled out of
// production builds; the server cannot tell a bot-driven client apart anyway (see README, "Security model").
const allowAutopilot = import.meta.env.DEV || import.meta.env.VITE_E2E_AUTOPILOT === 'true';
const online = useOnlineMatch(Number(props.id), { autopilot: allowAutopilot && route.query.autopilot === '1' });
const { status, error, result, countdown, me, opponent, opp, stats, game, frame } = online;

useKeyboard(() => game.value);

// On touch screens the actions sit under the HOLD panel, leaving the bottom of the screen to the board and buttons.
const touch = window.matchMedia('(pointer: coarse)').matches;
const active = computed(() => status.value === 'playing' || status.value === 'countdown');
const actionProps = computed(() => ({
    canClaim: online.canClaim(),
    opponentName: opponent.value.user.name,
    idleSeconds: Math.floor(online.opponentIdleMs() / 1000),
    resyncs: stats.resyncs,
}));

watch(status, (value) => {
    if (value === 'finished') refreshSession();
    if (value === 'spectating') router.replace({ name: 'watch', params: { id: props.id } });
});
</script>

<template>
    <div class="pointer-coarse:flex pointer-coarse:min-h-0 pointer-coarse:flex-1 pointer-coarse:flex-col" :data-status="status" data-testid="match-view">
        <p v-if="status === 'loading'" class="py-20 text-center text-slate-400">Loading match…</p>
        <p v-else-if="status === 'error'" class="py-20 text-center text-red-400">{{ error }}</p>

        <GameLayout v-else-if="me && opponent" :frame="frame" :game="game" :playing="status === 'playing'" :countdown="status === 'countdown' ? countdown : null">
            <template #header>
                <p
                    v-if="realtime.state !== 'connected' && ['countdown', 'playing'].includes(status)"
                    class="mb-3 shrink-0 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-center text-sm text-amber-200"
                    role="status"
                    data-testid="realtime-warning"
                >
                    Reconnecting to the live server… Your pieces are still being saved, but your opponent's board is paused.
                </p>
            </template>

            <template #hud>
                <PlayerHud :name="me.user.name" :rating="me.rating_before" :score="stats.score" :lines="stats.lines" :sent="stats.sent" />
                <PlayerHud
                    :name="opponent.user.name"
                    :rating="opponent.rating_before"
                    :score="opp.score"
                    :lines="opp.lines"
                    :sent="opp.sent"
                    :online="opp.online"
                    :bot="opponent.user.is_bot"
                    align="right"
                />
            </template>

            <template v-if="touch && active" #under-hold>
                <MatchActions stacked v-bind="actionProps" @claim="online.claim()" @forfeit="online.forfeit()" />
            </template>

            <template #overlay>
                <div v-if="status === 'waiting'" class="pointer-events-none absolute inset-0 grid place-items-center">
                    <span class="rounded-lg bg-slate-900/90 px-4 py-2 text-slate-300">Topped out — confirming result…</span>
                </div>
                <div v-if="status === 'finished' && result" class="absolute inset-0 grid place-items-center overflow-y-auto bg-slate-950/60 p-4">
                    <ResultCard :match="result" :viewer-id="session.user?.id" class="w-full max-w-lg">
                        <div class="mt-6 flex flex-wrap justify-center gap-3">
                            <RouterLink to="/play" class="rounded-lg bg-cyan-500 px-5 py-2 font-semibold text-slate-950 hover:bg-cyan-400" data-testid="play-again">Play again</RouterLink>
                            <RouterLink :to="`/replay/${result.id}`" class="rounded-lg border border-slate-700 px-5 py-2 hover:bg-slate-800" data-testid="watch-replay">Watch replay</RouterLink>
                            <RouterLink to="/leaderboard" class="rounded-lg px-5 py-2 text-slate-300 hover:bg-slate-800">Leaderboard</RouterLink>
                        </div>
                    </ResultCard>
                </div>
            </template>

            <template v-if="!touch && active" #footer>
                <MatchActions class="mt-4 shrink-0" v-bind="actionProps" @claim="online.claim()" @forfeit="online.forfeit()" />
            </template>
        </GameLayout>
    </div>
</template>
