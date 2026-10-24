<script setup>
import { computed } from 'vue';
import AuthPanel from '../components/AuthPanel.vue';
import { session } from '../lib/session';
import { ACTIONS, keyLabel, settings } from '../lib/settings';

const controls = computed(() =>
    ACTIONS.map(([action, label]) => [settings.bindings[action].map(keyLabel).join(' / ') || '—', label]),
);

const touchControls = [
    ['◀ ▶', 'Move'],
    ['▼', 'Soft drop'],
    ['⟲ ⟳', 'Rotate'],
    ['Drop', 'Hard drop'],
    ['Hold', 'Hold piece'],
];
</script>

<template>
    <!-- Below lg the section's children join the grid, so the sign-in panel can come before Controls, and on phones the
         Play buttons straight after the headline. -->
    <div class="grid gap-6 lg:grid-cols-[1fr_380px] lg:gap-10">
        <section class="flex flex-col gap-6 max-lg:contents">
            <h1 class="text-3xl font-bold tracking-tight sm:text-5xl">
                Real-time 1v1 block stacking.
                <span class="block text-cyan-400">Clear lines, send garbage, top them out.</span>
            </h1>
            <p class="max-w-2xl text-lg text-slate-400 max-sm:order-1 max-sm:text-base">
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
            <div class="max-w-lg rounded-2xl border border-slate-800 bg-slate-900/60 p-5 max-lg:order-3">
                <h2 class="mb-3 text-sm font-semibold tracking-widest text-slate-400 uppercase">Controls</h2>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm pointer-coarse:hidden">
                    <template v-for="[key, action] in controls" :key="action">
                        <dt class="font-mono text-cyan-300">{{ key }}</dt>
                        <dd class="text-slate-300">{{ action }}</dd>
                    </template>
                </dl>
                <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 text-sm pointer-fine:hidden">
                    <template v-for="[button, action] in touchControls" :key="action">
                        <dt class="font-mono text-cyan-300">{{ button }}</dt>
                        <dd class="text-slate-300">{{ action }}</dd>
                    </template>
                </dl>
                <p class="mt-3 text-xs text-slate-500 pointer-coarse:hidden">
                    Change keys, DAS and ARR in <RouterLink to="/settings" class="text-cyan-300 hover:underline">Settings</RouterLink>. On phones and
                    tablets, on-screen buttons appear under the board.
                </p>
                <p class="mt-3 text-xs text-slate-500 pointer-fine:hidden">These buttons appear under the board during a game.</p>
            </div>
        </section>
        <aside v-if="!session.user" class="max-lg:order-2">
            <AuthPanel />
        </aside>
    </div>
</template>
