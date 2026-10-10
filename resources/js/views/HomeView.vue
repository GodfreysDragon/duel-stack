<script setup>
import AuthPanel from '../components/AuthPanel.vue';
import { session } from '../lib/session';

const controls = [
    ['← →', 'Move'],
    ['↓', 'Soft drop'],
    ['Space', 'Hard drop'],
    ['↑ / X', 'Rotate right'],
    ['Z / Ctrl', 'Rotate left'],
    ['A', 'Rotate 180°'],
    ['C / Shift', 'Hold'],
];
</script>

<template>
    <div class="grid gap-10 lg:grid-cols-[1fr_380px]">
        <section class="space-y-6">
            <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                Real-time 1v1 block stacking.
                <span class="block text-cyan-400">Clear lines, send garbage, top them out.</span>
            </h1>
            <p class="max-w-2xl text-lg text-slate-400">
                Get matched against a player near your rating. Both of you get the same pieces. Every line you clear pushes
                rows of garbage onto your opponent's board, and the first player whose stack reaches the top loses.
            </p>
            <div v-if="session.user" class="flex flex-wrap gap-3">
                <RouterLink
                    :to="session.activeMatchId ? `/match/${session.activeMatchId}` : '/play'"
                    class="rounded-xl bg-cyan-500 px-6 py-3 text-lg font-semibold text-slate-950 hover:bg-cyan-400"
                    data-testid="play-ranked"
                >
                    {{ session.activeMatchId ? 'Rejoin your match' : 'Play ranked' }}
                </RouterLink>
                <RouterLink to="/practice" class="rounded-xl border border-slate-700 px-6 py-3 text-lg font-semibold hover:bg-slate-900">
                    Practice vs bot
                </RouterLink>
            </div>
            <div class="max-w-lg rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
                <h2 class="mb-3 text-sm font-semibold tracking-widest text-slate-400 uppercase">Controls</h2>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                    <template v-for="[key, action] in controls" :key="key">
                        <dt class="font-mono text-cyan-300">{{ key }}</dt>
                        <dd class="text-slate-300">{{ action }}</dd>
                    </template>
                </dl>
                <p class="mt-3 text-xs text-slate-500">On phones and tablets, on-screen buttons appear under the board.</p>
            </div>
        </section>
        <aside v-if="!session.user">
            <AuthPanel />
        </aside>
    </div>
</template>
