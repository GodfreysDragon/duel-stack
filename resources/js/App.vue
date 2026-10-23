<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { logout, session } from './lib/session';
import { sound, toggleMuted } from './lib/sound';

const route = useRoute();
const router = useRouter();
// On touch screens a game page fills the window exactly, and on a phone the header shrinks to one line, so the
// board and its buttons fit without scrolling.
const fitScreen = computed(() => route.meta.fitScreen === true);

async function signOut() {
    await logout();
    router.push({ name: 'home' });
}
</script>

<template>
    <div class="flex min-h-full flex-col" :class="{ 'pointer-coarse:h-dvh': fitScreen }">
        <header class="border-b border-slate-800 bg-slate-950/80 backdrop-blur">
            <nav class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <RouterLink to="/" class="flex items-center gap-2 text-lg font-bold tracking-tight">
                    <span class="grid grid-cols-2 gap-0.5" aria-hidden="true">
                        <span class="size-2 rounded-[2px] bg-cyan-400"></span><span class="size-2 rounded-[2px] bg-purple-500"></span>
                        <span class="size-2 rounded-[2px] bg-orange-500"></span><span class="size-2 rounded-[2px] bg-green-500"></span>
                    </span>
                    Duel Stack
                </RouterLink>
                <div class="flex items-center gap-4 text-sm text-slate-400" :class="{ 'pointer-coarse:max-sm:hidden': fitScreen }">
                    <RouterLink to="/leaderboard" class="hover:text-white" active-class="text-white">Leaderboard</RouterLink>
                    <RouterLink to="/practice" class="hover:text-white" active-class="text-white">Practice</RouterLink>
                    <RouterLink to="/settings" class="hover:text-white" active-class="text-white">Settings</RouterLink>
                </div>
                <div class="ml-auto flex items-center gap-3 text-sm">
                    <button
                        type="button"
                        class="rounded px-1.5 py-0.5 text-slate-400 hover:text-white"
                        :aria-pressed="sound.muted"
                        :title="sound.muted ? 'Sound off' : 'Sound on'"
                        data-testid="toggle-sound"
                        @click="toggleMuted"
                    >
                        <span aria-hidden="true">{{ sound.muted ? '🔇' : '🔊' }}</span>
                        <span class="sr-only">{{ sound.muted ? 'Turn sound on' : 'Turn sound off' }}</span>
                    </button>
                    <template v-if="session.user">
                        <RouterLink :to="`/players/${session.user.name}`" class="font-medium hover:text-cyan-300" data-testid="nav-user">
                            {{ session.user.name }}
                        </RouterLink>
                        <span class="rounded bg-slate-800 px-2 py-0.5 font-mono text-xs text-slate-300">{{ session.user.rating }}</span>
                        <button type="button" class="text-slate-400 hover:text-white" :class="{ 'pointer-coarse:max-sm:hidden': fitScreen }" @click="signOut">Sign out</button>
                    </template>
                </div>
            </nav>
        </header>
        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6" :class="{ 'pointer-coarse:flex pointer-coarse:min-h-0 pointer-coarse:flex-col pointer-coarse:py-2': fitScreen }">
            <RouterView :key="$route.fullPath" />
        </main>
    </div>
</template>
