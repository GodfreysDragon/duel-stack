<script setup>
import { ref, watchEffect } from 'vue';
import { api } from '../lib/api';
import { session } from '../lib/session';

const sort = ref('rating');
const players = ref([]);
const loading = ref(true);

watchEffect(async () => {
    loading.value = true;
    players.value = await api('GET', `/api/leaderboard?sort=${sort.value}`);
    loading.value = false;
});

const winRate = (p) => (p.games_played ? Math.round((p.wins / p.games_played) * 100) : 0);
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold">Leaderboard</h1>
            <div class="ml-auto flex gap-1 rounded-lg bg-slate-900 p-1 text-sm">
                <button
                    v-for="[key, label] in [['rating', 'Rating'], ['best_score', 'Best score']]"
                    :key="key"
                    type="button"
                    class="rounded-md px-3 py-1"
                    :class="sort === key ? 'bg-slate-700 text-white' : 'text-slate-400'"
                    @click="sort = key"
                >
                    {{ label }}
                </button>
            </div>
        </div>
        <div class="overflow-x-auto rounded-2xl border border-slate-800">
            <table class="w-full min-w-[520px] text-sm" data-testid="leaderboard">
                <thead class="bg-slate-900 text-left text-xs text-slate-400 uppercase">
                    <tr>
                        <th class="px-4 py-3 font-medium">#</th>
                        <th class="px-4 py-3 font-medium">Player</th>
                        <th class="px-4 py-3 text-right font-medium">Rating</th>
                        <th class="px-4 py-3 text-right font-medium">W–L</th>
                        <th class="px-4 py-3 text-right font-medium">Win %</th>
                        <th class="px-4 py-3 text-right font-medium">Best score</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(p, i) in players"
                        :key="p.id"
                        class="border-t border-slate-800"
                        :class="p.id === session.user?.id ? 'bg-cyan-500/10' : ''"
                    >
                        <td class="px-4 py-2.5 font-mono text-slate-500">{{ i + 1 }}</td>
                        <td class="px-4 py-2.5">
                            <RouterLink :to="`/players/${p.name}`" class="font-medium hover:text-cyan-300">{{ p.name }}</RouterLink>
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ p.rating }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-slate-300">{{ p.wins }}–{{ p.losses }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-slate-300">{{ winRate(p) }}%</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ p.best_score.toLocaleString() }}</td>
                    </tr>
                    <tr v-if="!loading && players.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-slate-500">No ranked games yet. Be the first!</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
