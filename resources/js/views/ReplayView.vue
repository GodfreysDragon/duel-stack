<script setup>
import { computed, onMounted, ref } from 'vue';
import GameStage from '../components/GameStage.vue';
import ResultCard from '../components/ResultCard.vue';
import { api } from '../lib/api';
import { session } from '../lib/session';
import { Randomizer } from '../engine/randomizer.js';
import { applyLock, initialState, preview } from '../engine/simulation.js';
import { emptyBoard } from '../engine/board.js';

/**
 * Rebuilds both boards from the recorded locks with the same engine the game
 * uses: every stored move (including the garbage that rose with it) is
 * re-applied in order, so a replay is exact rather than a video.
 */
const props = defineProps({ id: { type: String, required: true } });

const match = ref(null);
const error = ref(null);
const timelines = ref([]); // per slot: [{ at, state, cleared }]
const time = ref(0);
const playing = ref(true);
const speed = ref(1);
const focus = ref(0);

const duration = computed(() => Math.max(0, ...timelines.value.map((t) => t.at(-1)?.at ?? 0)) + 1500);

onMounted(async () => {
    try {
        const data = await api('GET', `/api/matches/${props.id}/replay`);
        match.value = data.match;
        timelines.value = data.match.players.map((player) => {
            const randomizer = new Randomizer(data.match.seed);
            let state = initialState(randomizer);
            const frames = [{ at: 0, state, cleared: [], randomizer }];
            for (const move of data.moves[player.user.id] ?? []) {
                const result = applyLock(state, randomizer, { ...move, garbage: move.garbage ?? [] });
                state = result.state;
                frames.push({ at: move.at_ms, state, cleared: result.cleared, randomizer });
            }
            return frames;
        });
    } catch (e) {
        error.value = e.message;
    }
});

function frameAt(slot) {
    const frames = timelines.value[slot];
    if (!frames) return null;
    let lo = 0;
    let hi = frames.length - 1;
    while (lo < hi) {
        const mid = Math.ceil((lo + hi) / 2);
        if (frames[mid].at <= time.value) lo = mid;
        else hi = mid - 1;
    }
    return frames[lo];
}

function view(slot) {
    const f = frameAt(slot);
    if (!f) return { board: emptyBoard() };
    return {
        board: f.state.board,
        hold: f.state.hold,
        next: preview(f.state, f.randomizer, 5),
        clear: f.cleared.length ? { rows: f.cleared, at: performance.now() - Math.min(220, (time.value - f.at) / speed.value) } : null,
        toppedOut: f.state.toppedOut,
    };
}

function frame(dt) {
    if (playing.value && timelines.value.length) {
        time.value = Math.min(duration.value, time.value + dt * speed.value);
        if (time.value >= duration.value) playing.value = false;
    }
    return { me: view(focus.value), opponent: view(1 - focus.value) };
}

const format = (ms) => `${Math.floor(ms / 60000)}:${String(Math.floor((ms % 60000) / 1000)).padStart(2, '0')}`;
const stat = (slot) => frameAt(slot)?.state;

function togglePlay() {
    if (time.value >= duration.value) time.value = 0;
    playing.value = !playing.value;
}
</script>

<template>
    <p v-if="error" class="py-20 text-center text-slate-400">{{ error }}</p>
    <div v-else-if="match" data-testid="replay-view">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <template v-for="slot in [focus, 1 - focus]" :key="slot">
                <div class="flex items-baseline gap-3" :class="slot !== focus ? 'justify-end' : ''">
                    <span class="font-semibold">{{ match.players[slot].user.name }}</span>
                    <span class="font-mono text-sm text-slate-300">{{ (stat(slot)?.score ?? 0).toLocaleString() }}</span>
                    <span class="text-xs text-slate-500">{{ stat(slot)?.lines ?? 0 }} lines · {{ stat(slot)?.pieces ?? 0 }} pieces</span>
                </div>
            </template>
        </div>

        <GameStage :frame="frame" />

        <div class="mx-auto mt-4 flex max-w-3xl flex-wrap items-center gap-3 text-sm">
            <button type="button" class="w-20 rounded-lg bg-cyan-500 py-1.5 font-semibold text-slate-950" @click="togglePlay">
                {{ playing ? 'Pause' : 'Play' }}
            </button>
            <input v-model.number="time" type="range" min="0" :max="duration" step="50" class="flex-1 accent-cyan-400" aria-label="Replay position" />
            <span class="w-24 text-right font-mono text-slate-400">{{ format(time) }} / {{ format(duration) }}</span>
            <select v-model.number="speed" class="rounded-lg border border-slate-700 bg-slate-900 px-2 py-1">
                <option v-for="s in [0.5, 1, 2, 4]" :key="s" :value="s">{{ s }}×</option>
            </select>
            <button type="button" class="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800" @click="focus = 1 - focus">Swap boards</button>
        </div>

        <ResultCard :match="match" :viewer-id="session.user?.id" class="mx-auto mt-6 max-w-lg" />
    </div>
</template>
