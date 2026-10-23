<script setup>
import { useRouter } from 'vue-router';
import { logout, session } from './lib/session';
import { sound, toggleMuted } from './lib/sound';

const router = useRouter();

async function signOut() {
    await logout();
    router.push({ name: 'home' });
}
</script>

<template>
    <div class="flex min-h-full flex-col">
        <header class="border-b border-slate-800 bg-slate-950/80 backdrop-blur">
            <nav class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <RouterLink to="/" class="flex items-center gap-2 text-lg font-bold tracking-tight">
                    <span class="grid grid-cols-2 gap-0.5" aria-hidden="true">
                        <span class="size-2 rounded-[2px] bg-cyan-400"></span><span class="size-2 rounded-[2px] bg-purple-500"></span>
                        <span class="size-2 rounded-[2px] bg-orange-500"></span><span class="size-2 rounded-[2px] bg-green-500"></span>
                    </span>
                    Duel Stack
                </RouterLink>
                <div class="flex items-center gap-4 text-sm text-slate-400">
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
                        <button type="button" class="text-slate-400 hover:text-white" @click="signOut">Sign out</button>
                    </template>
                </div>
            </nav>
        </header>
        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
            <RouterView :key="$route.fullPath" />
        </main>
    </div>
</template>
