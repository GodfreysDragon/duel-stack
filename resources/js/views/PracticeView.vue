<script setup>
import { ref } from 'vue';
import GameStage from '../components/GameStage.vue';
import PlayerHud from '../components/PlayerHud.vue';
import TouchControls from '../components/TouchControls.vue';
import { useKeyboard } from '../composables/useKeyboard';
import { usePracticeMatch } from '../composables/usePracticeMatch';
import { DIFFICULTIES } from '../engine/bot.js';
import { session } from '../lib/session';

const practice = usePracticeMatch();
const { status, countdown, winner, stats, game, frame } = practice;
const difficulty = ref('medium');
// On a touch screen the board's box matches the canvas (see GameStage), and the buttons take the height left over.
const boardAspect = ref(null);

useKeyboard(() => game.value);
</script>

<template>
    <div class="pointer-coarse:flex pointer-coarse:min-h-0 pointer-coarse:flex-1 pointer-coarse:flex-col" data-testid="practice-view" :data-status="status">
        <div class="mb-4 flex shrink-0 flex-wrap items-center gap-3 pointer-coarse:mb-2">
            <h1 class="text-xl font-bold max-sm:text-lg">Practice vs bot</h1>
            <span class="text-sm text-slate-500 max-sm:hidden">Unranked · runs entirely in your browser</span>
            <div class="ml-auto flex items-center gap-2">
                <select v-model="difficulty" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm" :disabled="status === 'playing' || status === 'countdown'">
                    <option v-for="(settings, name) in DIFFICULTIES" :key="name" :value="name">
                        {{ name[0].toUpperCase() + name.slice(1) }} · ~{{ settings.piecesPerSecond }} pieces/s
                    </option>
                </select>
                <button
                    type="button"
                    class="rounded-lg bg-cyan-500 px-4 py-1.5 font-semibold text-slate-950 hover:bg-cyan-400 disabled:opacity-40"
                    :disabled="status === 'playing' || status === 'countdown'"
                    data-testid="practice-start"
                    @click="practice.start(difficulty)"
                >
                    {{ status === 'idle' ? 'Start' : 'Restart' }}
                </button>
            </div>
        </div>

        <div class="mb-3 flex shrink-0 flex-wrap items-end justify-between gap-x-2 gap-y-1 pointer-coarse:mb-2">
            <PlayerHud :name="session.user?.name ?? 'You'" :score="stats.you.score" :lines="stats.you.lines" :sent="stats.you.sent" />
            <PlayerHud :name="`Bot (${difficulty})`" :score="stats.bot.score" :lines="stats.bot.lines" :sent="stats.bot.sent" align="right" />
        </div>

        <div class="relative pointer-coarse:mb-2 pointer-coarse:min-h-0" :style="{ aspectRatio: boardAspect }">
            <GameStage :frame="frame" fit @aspect="boardAspect = $event" />
            <div v-if="status === 'idle'" class="absolute inset-0 grid place-items-center">
                <p class="rounded-lg bg-slate-900/90 px-5 py-3 text-slate-300">Pick a difficulty and press <strong>Start</strong>.</p>
            </div>
            <div v-if="status === 'countdown'" class="pointer-events-none absolute inset-0 grid place-items-center">
                <span class="text-8xl font-black text-white">{{ countdown }}</span>
            </div>
            <div v-if="status === 'finished'" class="absolute inset-0 grid place-items-center overflow-y-auto bg-slate-950/50 p-4">
                <div class="rounded-2xl border border-slate-700 bg-slate-900 px-8 py-6 text-center" data-testid="practice-result">
                    <h2 class="text-4xl font-bold" :class="winner === 'you' ? 'text-cyan-300' : 'text-slate-200'">
                        {{ winner === 'you' ? 'You win!' : 'The bot wins' }}
                    </h2>
                    <p class="mt-2 text-slate-400">{{ stats.you.lines }} lines · {{ stats.you.score.toLocaleString() }} points</p>
                    <button type="button" class="mt-4 rounded-lg bg-cyan-500 px-5 py-2 font-semibold text-slate-950" @click="practice.start(difficulty)">
                        Rematch
                    </button>
                </div>
            </div>
        </div>

        <!-- Kept in place, hidden, outside play: the board does not jump when the buttons come and go. -->
        <TouchControls :class="{ invisible: status !== 'playing' }" @press="game?.press($event)" @release="game?.release($event)" />
    </div>
</template>
