<script setup>
/** Name, rating and running stats above a board. */
defineProps({
    name: { type: String, required: true },
    rating: { type: Number, default: null },
    score: { type: Number, default: 0 },
    lines: { type: Number, default: 0 },
    sent: { type: Number, default: 0 },
    online: { type: Boolean, default: null },
    bot: { type: Boolean, default: false },
    align: { type: String, default: 'left' },
});
</script>

<template>
    <!-- One line however narrow: a long name is cut short rather than wrapping the stats. -->
    <div class="flex min-w-0 items-baseline gap-3 whitespace-nowrap max-sm:gap-2" :class="align === 'right' ? 'justify-end' : ''">
        <span v-if="online !== null" class="size-2 shrink-0 self-center rounded-full" :class="online ? 'bg-green-400' : 'bg-slate-600'" :title="online ? 'Connected' : 'Disconnected'"></span>
        <span class="min-w-0 truncate font-semibold">{{ name }}</span>
        <span v-if="bot" class="rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-semibold tracking-wider text-slate-300">BOT</span>
        <span v-if="rating !== null" class="font-mono text-xs text-slate-400">{{ rating }}</span>
        <span class="ml-2 font-mono text-sm text-slate-300 max-sm:ml-1" data-testid="hud-score">{{ score.toLocaleString() }}</span>
        <span class="text-xs text-slate-500">{{ lines }} lines · {{ sent }} sent</span>
    </div>
</template>
