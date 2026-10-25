<script setup>
import { ref } from 'vue';
import GameStage from './GameStage.vue';
import TouchControls from './TouchControls.vue';

/**
 * The page around a game: the view's header, the HUD, the board with the countdown and the view's overlays, and the
 * touch buttons. On a touch screen it fills the screen exactly: the board's box matches the canvas (see GameStage)
 * and the buttons take the height left over.
 */
defineProps({
    frame: { type: Function, required: true },
    game: { type: Object, default: null },
    playing: { type: Boolean, default: false },
    countdown: { type: Number, default: null },
});

const boardAspect = ref(null);
</script>

<template>
    <div class="pointer-coarse:flex pointer-coarse:min-h-0 pointer-coarse:flex-1 pointer-coarse:flex-col">
        <slot name="header" />

        <div class="mb-3 flex shrink-0 flex-wrap items-end justify-between gap-x-2 gap-y-1 pointer-coarse:mb-2">
            <slot name="hud" />
        </div>

        <div class="relative pointer-coarse:mb-2 pointer-coarse:min-h-0" :style="{ aspectRatio: boardAspect }">
            <GameStage :frame="frame" fit @aspect="boardAspect = $event">
                <template v-if="$slots['under-hold']" #under-hold>
                    <slot name="under-hold" />
                </template>
            </GameStage>

            <div v-if="countdown !== null" class="pointer-events-none absolute inset-0 grid place-items-center">
                <span class="text-8xl font-black text-white drop-shadow-[0_0_30px_rgba(34,211,238,0.6)]" data-testid="countdown">{{ countdown }}</span>
            </div>
            <slot name="overlay" />
        </div>

        <!-- Kept in place, hidden, outside play: the board does not jump when the buttons come and go. -->
        <TouchControls :class="{ invisible: !playing }" @press="game?.press($event)" @release="game?.release($event)" />

        <slot name="footer" />
    </div>
</template>
