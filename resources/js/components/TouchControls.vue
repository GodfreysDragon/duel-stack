<script setup>
/** On-screen buttons for phones and tablets; same actions as the keyboard. */
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
    <div class="mx-auto mt-4 grid max-w-sm grid-cols-4 gap-2 select-none [@media(pointer:fine)]:hidden">
        <button
            v-for="button in buttons"
            :key="button.action"
            type="button"
            class="rounded-xl bg-slate-800 py-4 text-lg font-semibold text-slate-200 active:bg-slate-700"
            :class="{ 'col-span-2': button.action === 'down' }"
            @pointerdown.prevent="emit('press', button.action)"
            @pointerup.prevent="emit('release', button.action)"
            @pointerleave="emit('release', button.action)"
        >
            {{ button.label }}
        </button>
    </div>
</template>
