<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../lib/api';
import { session } from '../lib/session';

const props = defineProps({ name: { type: String, required: true } });
const profile = ref(null);
const error = ref(null);

onMounted(async () => {
    try {
        profile.value = await api('GET', `/api/players/${encodeURIComponent(props.name)}`);
    } catch (e) {
        error.value = e.status === 404 ? 'No such player.' : e.message;
    }
});

function outcome(match) {
    const self = match.players.find((p) => p.user.name === props.name);
    const other = match.players.find((p) => p.user.name !== props.name);
    const won = match.winner_id === self.user.id;
    const delta = (self.rating_after ?? self.rating_before) - self.rating_before;
    return { self, other, won, delta, noContest: match.winner_id === null };
}
</script>

<template>
    <p v-if="error" class="py-20 text-center text-slate-400">{{ error }}</p>
    <div v-else-if="profile" class="mx-auto max-w-3xl space-y-6" data-testid="profile">
        <div class="flex flex-wrap items-baseline gap-3">
            <h1 class="text-3xl font-bold">{{ profile.user.name }}</h1>
            <span v-if="profile.rank" class="rounded bg-slate-800 px-2 py-0.5 text-sm text-slate-300">Rank #{{ profile.rank }}</span>
        </div>
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div v-for="[label, value] in [
                ['Rating', profile.user.rating],
                ['Games', profile.user.games_played],
                ['Wins', profile.user.wins],
                ['Lines', profile.user.total_lines.toLocaleString()],
                ['Best score', profile.user.best_score.toLocaleString()],
            ]" :key="label" class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <dt class="text-xs text-slate-500 uppercase">{{ label }}</dt>
                <dd class="mt-1 font-mono text-xl" :data-testid="`stat-${label.toLowerCase().replace(' ', '-')}`">{{ value }}</dd>
            </div>
        </dl>

        <section>
            <h2 class="mb-2 text-sm font-semibold tracking-widest text-slate-400 uppercase">Recent matches</h2>
            <ul class="divide-y divide-slate-800 rounded-2xl border border-slate-800">
                <li v-for="match in profile.recent_matches" :key="match.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                    <template v-for="o in [outcome(match)]" :key="match.id">
                        <span
                            class="w-12 rounded px-2 py-0.5 text-center text-xs font-bold"
                            :class="o.noContest ? 'bg-slate-800 text-slate-400' : o.won ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300'"
                        >
                            {{ o.noContest ? 'N/C' : o.won ? 'WIN' : 'LOSS' }}
                        </span>
                        <span>
                            vs
                            <RouterLink :to="`/players/${o.other.user.name}`" class="font-medium hover:text-cyan-300">{{ o.other.user.name }}</RouterLink>
                        </span>
                        <span class="font-mono text-slate-400">{{ o.self.score.toLocaleString() }} pts · {{ o.self.lines }} lines</span>
                        <span class="font-mono" :class="o.delta > 0 ? 'text-green-400' : o.delta < 0 ? 'text-red-400' : 'text-slate-500'">
                            {{ o.delta > 0 ? '+' : '' }}{{ o.delta }}
                        </span>
                        <RouterLink :to="`/replay/${match.id}`" class="ml-auto text-cyan-300 hover:underline">Replay</RouterLink>
                    </template>
                </li>
                <li v-if="profile.recent_matches.length === 0" class="px-4 py-6 text-center text-slate-500">No finished matches yet.</li>
            </ul>
        </section>
    </div>
</template>
