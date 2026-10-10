<script setup>
/** Name, rating and running stats above a board. */
defineProps({
    name: { type: String, required: true },
    rating: { type: Number, default: null },
    score: { type: Number, default: 0 },
    lines: { type: Number, default: 0 },
    sent: { type: Number, default: 0 },
    online: { type: Boolean, default: null },
    align: { type: String, default: 'left' },
});
</script>

<template>
    <div class="flex items-baseline gap-3" :class="align === 'right' ? 'justify-end' : ''">
        <span v-if="online !== null" class="size-2 self-center rounded-full" :class="online ? 'bg-green-400' : 'bg-slate-600'" :title="online ? 'Connected' : 'Disconnected'"></span>
        <span class="font-semibold">{{ name }}</span>
        <span v-if="rating !== null" class="font-mono text-xs text-slate-400">{{ rating }}</span>
        <span class="ml-2 font-mono text-sm text-slate-300" data-testid="hud-score">{{ score.toLocaleString() }}</span>
        <span class="text-xs text-slate-500">{{ lines }} lines · {{ sent }} sent</span>
    </div>
</template>
