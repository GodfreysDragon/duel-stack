<script setup>
import { ref } from 'vue';

/**
 * Forfeit, Claim the win and the resync count during a match. `stacked` lays them out as a column, for the narrow
 * space under the HOLD panel on touch screens.
 */
defineProps({
    stacked: { type: Boolean, default: false },
    canClaim: { type: Boolean, default: false },
    opponentName: { type: String, required: true },
    idleSeconds: { type: Number, default: 0 },
    resyncs: { type: Number, default: 0 },
});
const emit = defineEmits(['claim', 'forfeit']);

// Asked in the page rather than with window.confirm, which looks out of place on phones and stalls the game loop.
const confirmingForfeit = ref(false);
</script>

<template>
    <div v-if="stacked" class="flex flex-col gap-2 text-center text-xs leading-tight">
        <!-- "Keep playing" takes Forfeit's place, so a double tap does not forfeit. -->
        <template v-if="confirmingForfeit">
            <button type="button" class="rounded-lg border border-slate-700 bg-slate-900 px-1 py-2.5 text-slate-200" @click="confirmingForfeit = false">
                Keep playing
            </button>
            <button type="button" class="rounded-lg bg-red-500 px-1 py-2.5 font-semibold text-white" data-testid="forfeit-confirm" @click="emit('forfeit')">
                Forfeit and lose
            </button>
        </template>
        <button
            v-else
            type="button"
            class="rounded-lg border border-slate-800 px-1 py-2.5 text-slate-400"
            data-testid="forfeit"
            @click="confirmingForfeit = true"
        >
            Forfeit
        </button>
        <template v-if="canClaim">
            <span class="mt-2 text-slate-400">Silent {{ idleSeconds }}s</span>
            <button type="button" class="rounded-lg bg-amber-500 px-1 py-2.5 font-semibold text-slate-950" @click="emit('claim')">Claim the win</button>
        </template>
        <span v-if="resyncs" class="text-amber-400" title="Times the server corrected this client">⟳ {{ resyncs }}</span>
    </div>

    <div v-else class="flex flex-wrap items-center justify-center gap-3 text-sm">
        <span v-if="resyncs" class="text-xs text-amber-400" title="Times the server corrected this client">⟳ {{ resyncs }} resync(s)</span>
        <template v-if="canClaim">
            <span class="text-slate-400">{{ opponentName }} has been silent for {{ idleSeconds }}s.</span>
            <button type="button" class="rounded-lg bg-amber-500 px-4 py-1.5 font-semibold text-slate-950" @click="emit('claim')">Claim the win</button>
        </template>
        <template v-if="confirmingForfeit">
            <button type="button" class="rounded-lg border border-slate-700 px-4 py-1.5 hover:bg-slate-800" @click="confirmingForfeit = false">Keep playing</button>
            <button
                type="button"
                class="rounded-lg bg-red-500 px-4 py-1.5 font-semibold text-white hover:bg-red-400"
                data-testid="forfeit-confirm"
                @click="emit('forfeit')"
            >
                Forfeit and lose
            </button>
        </template>
        <button v-else type="button" class="text-slate-500 hover:text-red-400" data-testid="forfeit" @click="confirmingForfeit = true">Forfeit</button>
    </div>
</template>
