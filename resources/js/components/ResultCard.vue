<script setup>
import { computed } from 'vue';

/** Final result of a ranked match, from the viewer's side. */
const props = defineProps({
    match: { type: Object, required: true },
    viewerId: { type: Number, default: null },
});

const REASONS = {
    topout: 'topped out',
    forfeit: 'forfeited',
    abandoned: 'left the match',
    no_contest: 'no contest',
};

const winner = computed(() => props.match.players.find((p) => p.user.id === props.match.winner_id));
const loser = computed(() => props.match.players.find((p) => p.user.id !== props.match.winner_id));
const viewerWon = computed(() => props.viewerId !== null && props.match.winner_id === props.viewerId);
const headline = computed(() => {
    if (!winner.value) return 'No contest';
    if (props.viewerId === null || !props.match.players.some((p) => p.user.id === props.viewerId)) return `${winner.value.user.name} wins`;
    return viewerWon.value ? 'Victory' : 'Defeat';
});
const delta = (p) => (p.rating_after ?? p.rating_before) - p.rating_before;
</script>

<template>
    <div class="rounded-2xl border border-slate-700 bg-slate-900/95 p-6 shadow-2xl" data-testid="result-card">
        <p class="text-center text-sm tracking-widest text-slate-400 uppercase">Match over</p>
        <h2
            class="mt-1 text-center text-4xl font-bold"
            :class="viewerWon ? 'text-cyan-300' : winner ? 'text-slate-100' : 'text-slate-400'"
            data-testid="result-headline"
        >
            {{ headline }}
        </h2>
        <p v-if="winner" class="mt-1 text-center text-sm text-slate-400">{{ loser.user.name }} {{ REASONS[match.end_reason] }}</p>

        <table class="mt-5 w-full text-sm">
            <thead class="text-left text-xs text-slate-500 uppercase">
                <tr>
                    <th class="pb-2 font-medium">Player</th>
                    <th class="pb-2 text-right font-medium">Score</th>
                    <th class="pb-2 text-right font-medium">Lines</th>
                    <th class="pb-2 text-right font-medium">Sent</th>
                    <th class="pb-2 text-right font-medium">Rating</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in match.players" :key="p.user.id" class="border-t border-slate-800">
                    <td class="py-2 font-medium">
                        {{ p.user.name }}<span v-if="p.user.id === match.winner_id" class="ml-1 text-yellow-300">★</span>
                    </td>
                    <td class="py-2 text-right font-mono">{{ p.score.toLocaleString() }}</td>
                    <td class="py-2 text-right font-mono">{{ p.lines }}</td>
                    <td class="py-2 text-right font-mono">{{ p.garbage_sent }}</td>
                    <td class="py-2 text-right font-mono">
                        {{ p.rating_after ?? p.rating_before }}
                        <span :class="delta(p) > 0 ? 'text-green-400' : delta(p) < 0 ? 'text-red-400' : 'text-slate-500'" data-testid="rating-delta">
                            ({{ delta(p) > 0 ? '+' : '' }}{{ delta(p) }})
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
        <slot />
    </div>
</template>
