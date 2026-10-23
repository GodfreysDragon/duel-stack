<script setup>
import { ref } from 'vue';
import GameStage from '../components/GameStage.vue';
import ResultCard from '../components/ResultCard.vue';
import { useSpectateMatch } from '../composables/useSpectateMatch';
import { session } from '../lib/session';

/** Watching someone else's match live; it turns into the result once the match is over. */
const props = defineProps({ id: { type: String, required: true } });

const { status, error, match, boards, frame } = useSpectateMatch(Number(props.id));
const focus = ref(0);
</script>

<template>
    <p v-if="status === 'loading'" class="py-20 text-center text-slate-400">Loading match…</p>
    <p v-else-if="status === 'error'" class="py-20 text-center text-red-400">{{ error }}</p>
    <div v-else-if="match" :data-status="status" data-testid="watch-view">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <template v-for="slot in [focus, 1 - focus]" :key="slot">
                <div class="flex items-baseline gap-3" :class="slot !== focus ? 'justify-end' : ''">
                    <RouterLink :to="`/players/${match.players[slot].user.name}`" class="font-semibold hover:text-cyan-300">
                        {{ match.players[slot].user.name }}
                    </RouterLink>
                    <span v-if="match.players[slot].user.is_bot" class="rounded bg-slate-800 px-1.5 py-0.5 text-xs font-bold text-slate-300">BOT</span>
                    <span class="font-mono text-sm text-slate-300">{{ boards[slot].score.toLocaleString() }}</span>
                    <span class="text-xs text-slate-500">{{ boards[slot].lines }} lines · {{ boards[slot].sent }} sent</span>
                </div>
            </template>
        </div>

        <GameStage :frame="() => frame(focus)" />

        <div class="mx-auto mt-4 flex max-w-3xl flex-wrap items-center gap-3 text-sm">
            <span v-if="status === 'live'" class="flex items-center gap-2 font-semibold text-red-400">
                <span class="size-2 animate-pulse rounded-full bg-red-500" aria-hidden="true"></span>
                Live
            </span>
            <span class="text-slate-500">You are watching.</span>
            <button type="button" class="ml-auto rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800" @click="focus = 1 - focus">
                Swap boards
            </button>
            <RouterLink v-if="status === 'finished'" :to="`/replay/${match.id}`" class="rounded-lg bg-cyan-500 px-3 py-1.5 font-semibold text-slate-950">
                Watch replay
            </RouterLink>
        </div>

        <ResultCard v-if="status === 'finished'" :match="match" :viewer-id="session.user?.id ?? null" class="mx-auto mt-6 max-w-lg" />
    </div>
</template>
