<script setup>
import { computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import GameStage from '../components/GameStage.vue';
import PlayerHud from '../components/PlayerHud.vue';
import ResultCard from '../components/ResultCard.vue';
import TouchControls from '../components/TouchControls.vue';
import { useKeyboard } from '../composables/useKeyboard';
import { useOnlineMatch } from '../composables/useOnlineMatch';
import { refreshSession, session } from '../lib/session';

const props = defineProps({ id: { type: String, required: true } });
const route = useRoute();

// ?autopilot=1 lets the bot play this side of a real match (used by the end-to-end test).
const online = useOnlineMatch(Number(props.id), { autopilot: route.query.autopilot === '1' });
const { status, error, result, countdown, me, opponent, opp, stats, game, frame } = online;

useKeyboard(() => game.value);

const idleSeconds = computed(() => Math.floor(online.opponentIdleMs() / 1000));

watch(status, (value) => {
    if (value === 'finished') refreshSession();
});

function confirmForfeit() {
    if (window.confirm('Forfeit this match? It counts as a loss.')) online.forfeit();
}
</script>

<template>
    <div class="relative" :data-status="status" data-testid="match-view">
        <p v-if="status === 'loading'" class="py-20 text-center text-slate-400">Loading match…</p>
        <p v-else-if="status === 'error'" class="py-20 text-center text-red-400">{{ error }}</p>

        <template v-else-if="me && opponent">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <PlayerHud :name="me.user.name" :rating="me.rating_before" :score="stats.score" :lines="stats.lines" :sent="stats.sent" />
                <PlayerHud
                    :name="opponent.user.name"
                    :rating="opponent.rating_before"
                    :score="opp.score"
                    :lines="opp.lines"
                    :sent="opp.sent"
                    :online="opp.online"
                    align="right"
                />
            </div>

            <div class="relative">
                <GameStage :frame="frame" />

                <div v-if="status === 'countdown'" class="pointer-events-none absolute inset-0 grid place-items-center">
                    <span class="text-8xl font-black text-white drop-shadow-[0_0_30px_rgba(34,211,238,0.6)]" data-testid="countdown">{{ countdown }}</span>
                </div>
                <div v-if="status === 'waiting'" class="pointer-events-none absolute inset-0 grid place-items-center">
                    <span class="rounded-lg bg-slate-900/90 px-4 py-2 text-slate-300">Topped out — confirming result…</span>
                </div>
                <div v-if="status === 'finished' && result" class="absolute inset-0 grid place-items-center bg-slate-950/60 p-4">
                    <ResultCard :match="result" :viewer-id="session.user?.id" class="w-full max-w-lg">
                        <div class="mt-6 flex flex-wrap justify-center gap-3">
                            <RouterLink to="/play" class="rounded-lg bg-cyan-500 px-5 py-2 font-semibold text-slate-950 hover:bg-cyan-400" data-testid="play-again">Play again</RouterLink>
                            <RouterLink :to="`/replay/${result.id}`" class="rounded-lg border border-slate-700 px-5 py-2 hover:bg-slate-800" data-testid="watch-replay">Watch replay</RouterLink>
                            <RouterLink to="/leaderboard" class="rounded-lg px-5 py-2 text-slate-300 hover:bg-slate-800">Leaderboard</RouterLink>
                        </div>
                    </ResultCard>
                </div>
            </div>

            <TouchControls v-if="status === 'playing'" @press="game?.press($event)" @release="game?.release($event)" />

            <div v-if="status === 'playing' || status === 'countdown'" class="mt-4 flex flex-wrap items-center justify-center gap-4 text-sm">
                <span v-if="stats.resyncs" class="text-xs text-amber-400" title="Times the server corrected this client">⟳ {{ stats.resyncs }} resync(s)</span>
                <template v-if="online.canClaim()">
                    <span class="text-slate-400">{{ opponent.user.name }} has been silent for {{ idleSeconds }}s.</span>
                    <button type="button" class="rounded-lg bg-amber-500 px-4 py-1.5 font-semibold text-slate-950" @click="online.claim()">Claim the win</button>
                </template>
                <button type="button" class="text-slate-500 hover:text-red-400" @click="confirmForfeit">Forfeit</button>
            </div>
        </template>
    </div>
</template>
