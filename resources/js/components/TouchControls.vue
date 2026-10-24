<script setup>
/**
 * On-screen buttons for phones and tablets; same actions as the keyboard. On a game page on a touch screen they grow
 * into spare height, up to a cap, and sit at the bottom of the screen.
 */
const emit = defineEmits(['press', 'release']);

const buttons = [
    { action: 'hold', label: 'Hold' },
    { action: 'rotateCCW', label: '⟲' },
    { action: 'rotateCW', label: '⟳' },
    { action: 'hardDrop', label: 'Drop' },
    { action: 'left', label: '◀' },
    { action: 'down', label: '▼' },
    { action: 'right', label: '▶' },
];
</script>

<template>
    <div class="mx-auto mt-4 grid w-full max-w-sm shrink-0 grid-cols-4 gap-2 select-none pointer-coarse:mt-auto pointer-coarse:max-h-44 pointer-coarse:min-h-28 pointer-coarse:flex-1 pointer-coarse:grid-rows-2 pointer-fine:hidden">
        <button
            v-for="button in buttons"
            :key="button.action"
            type="button"
            class="rounded-xl bg-slate-800 py-3 text-lg font-semibold text-slate-200 active:bg-slate-700"
            :class="{ 'col-span-2': button.action === 'down' }"
            @pointerdown.prevent="emit('press', button.action)"
            @pointerup.prevent="emit('release', button.action)"
            @pointerleave="emit('release', button.action)"
        >
            {{ button.label }}
        </button>
    </div>
</template>
