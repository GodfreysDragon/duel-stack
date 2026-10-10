<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../lib/api';
import { echo } from '../lib/echo';
import { session } from '../lib/session';

const POLL_MS = 2000;

const router = useRouter();
const searching = ref(true);
const waited = ref(0);
const info = ref({ players_waiting: 1, rating_window: 150 });
const error = ref(null);
let poll = null;
let clock = null;

function matched(matchId) {
    if (!searching.value) return;
    searching.value = false;
    session.activeMatchId = matchId;
    router.push({ name: 'match', params: { id: matchId } });
}

async function join() {
    try {
        const data = await api('POST', '/api/matchmaking');
        if (data.status === 'matched') return matched(data.match_id);
        info.value = data;
        error.value = null;
    } catch (e) {
        error.value = e.message;
    }
}

async function cancel() {
    searching.value = false;
    await api('DELETE', '/api/matchmaking');
    router.push({ name: 'home' });
}

onMounted(() => {
    // The pairing request usually comes from the *other* player, so we hear about it here.
    echo.private(`user.${session.user.id}`).listen('.match.found', (e) => matched(e.match_id));
    join();
    poll = setInterval(join, POLL_MS);
    clock = setInterval(() => waited.value++, 1000);
});

onBeforeUnmount(() => {
    clearInterval(poll);
    clearInterval(clock);
    echo.leave(`user.${session.user.id}`);
    if (searching.value) api('DELETE', '/api/matchmaking').catch(() => {});
});
</script>

<template>
    <div class="mx-auto max-w-md py-16 text-center" data-testid="matchmaking">
        <div class="mx-auto mb-8 grid w-16 grid-cols-2 gap-1" aria-hidden="true">
            <span class="aspect-square animate-pulse rounded bg-cyan-400"></span>
            <span class="aspect-square animate-pulse rounded bg-purple-500 [animation-delay:150ms]"></span>
            <span class="aspect-square animate-pulse rounded bg-orange-500 [animation-delay:450ms]"></span>
            <span class="aspect-square animate-pulse rounded bg-green-500 [animation-delay:300ms]"></span>
        </div>
        <h1 class="text-2xl font-bold">Finding an opponent…</h1>
        <p class="mt-2 text-slate-400">
            {{ waited }}s · {{ info.players_waiting }} in queue · matching ±{{ info.rating_window }} around
            <span class="font-mono text-slate-200">{{ session.user.rating }}</span>
        </p>
        <p class="mt-1 text-sm text-slate-500">The rating range widens the longer you wait.</p>
        <p v-if="error" class="mt-4 text-sm text-red-400">{{ error }}</p>
        <div class="mt-8 flex justify-center gap-3">
            <button type="button" class="rounded-lg border border-slate-700 px-5 py-2 hover:bg-slate-900" @click="cancel">Cancel</button>
            <RouterLink to="/practice" class="rounded-lg px-5 py-2 text-cyan-300 hover:bg-slate-900">Practice instead</RouterLink>
        </div>
    </div>
</template>
