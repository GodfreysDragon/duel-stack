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

useKeyboard(() => game.value);
</script>

<template>
    <div data-testid="practice-view" :data-status="status">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-bold">Practice vs bot</h1>
            <span class="text-sm text-slate-500">Unranked · runs entirely in your browser</span>
            <div class="ml-auto flex items-center gap-2">
                <select v-model="difficulty" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-1.5 text-sm" :disabled="status === 'playing' || status === 'countdown'">
                    <option v-for="(settings, name) in DIFFICULTIES" :key="name" :value="name">
                        {{ name[0].toUpperCase() + name.slice(1) }} · {{ settings.piecesPerSecond }} pieces/s
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

        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <PlayerHud :name="session.user?.name ?? 'You'" :score="stats.you.score" :lines="stats.you.lines" :sent="stats.you.sent" />
            <PlayerHud :name="`Bot (${difficulty})`" :score="stats.bot.score" :lines="stats.bot.lines" :sent="stats.bot.sent" align="right" />
        </div>

        <div class="relative">
            <GameStage :frame="frame" />
            <div v-if="status === 'idle'" class="absolute inset-0 grid place-items-center">
                <p class="rounded-lg bg-slate-900/90 px-5 py-3 text-slate-300">Pick a difficulty and press <strong>Start</strong>.</p>
            </div>
            <div v-if="status === 'countdown'" class="pointer-events-none absolute inset-0 grid place-items-center">
                <span class="text-8xl font-black text-white">{{ countdown }}</span>
            </div>
            <div v-if="status === 'finished'" class="absolute inset-0 grid place-items-center bg-slate-950/50">
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

        <TouchControls v-if="status === 'playing'" @press="game?.press($event)" @release="game?.release($event)" />
    </div>
</template>
