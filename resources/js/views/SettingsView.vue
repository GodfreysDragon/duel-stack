<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { ACTIONS, HANDLING_LIMITS, bindKey, keyLabel, resetSettings, saveSettings, settings, unbindKey } from '../lib/settings';

/** The action waiting for its next key press, if any. */
const listening = ref(null);

function capture(event) {
    if (!listening.value) return;
    event.preventDefault();
    if (event.code !== 'Escape') bindKey(listening.value, event.code);
    stopListening();
}

function listen(action) {
    listening.value = action;
    window.addEventListener('keydown', capture, { capture: true });
}

function stopListening() {
    listening.value = null;
    window.removeEventListener('keydown', capture, { capture: true });
}

onBeforeUnmount(stopListening);
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-8" data-testid="settings">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h1 class="text-3xl font-bold">Settings</h1>
            <p class="text-sm text-slate-500">Saved in this browser.</p>
        </div>

        <section>
            <h2 class="mb-2 text-sm font-semibold tracking-widest text-slate-400 uppercase">Keys</h2>
            <ul class="divide-y divide-slate-800 rounded-2xl border border-slate-800">
                <li v-for="[action, label] in ACTIONS" :key="action" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                    <span class="w-32 text-slate-300">{{ label }}</span>
                    <span class="flex flex-1 flex-wrap gap-2">
                        <span
                            v-for="code in settings.bindings[action]"
                            :key="code"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 py-1 pr-1.5 pl-2.5 font-mono text-cyan-300"
                        >
                            {{ keyLabel(code) }}
                            <button
                                type="button"
                                class="rounded px-1 text-slate-500 hover:text-red-400"
                                :aria-label="`Remove ${keyLabel(code)} from ${label}`"
                                @click="unbindKey(action, code)"
                            >
                                ×
                            </button>
                        </span>
                        <span v-if="settings.bindings[action].length === 0" class="py-1 text-slate-500">Not bound</span>
                    </span>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-1.5"
                        :class="listening === action ? 'border-cyan-400 text-cyan-300' : 'border-slate-700 hover:bg-slate-800'"
                        :data-testid="`bind-${action}`"
                        @click="listening === action ? stopListening() : listen(action)"
                    >
                        {{ listening === action ? 'Press a key… (Esc cancels)' : 'Add key' }}
                    </button>
                </li>
            </ul>
            <p class="mt-2 text-xs text-slate-500">A key belongs to one action: binding it here removes it from any other.</p>
        </section>

        <section>
            <h2 class="mb-2 text-sm font-semibold tracking-widest text-slate-400 uppercase">Handling</h2>
            <div class="space-y-4 rounded-2xl border border-slate-800 p-4">
                <label v-for="(limit, name) in HANDLING_LIMITS" :key="name" class="block text-sm">
                    <span class="flex items-baseline justify-between gap-3">
                        <span class="font-medium text-slate-200">{{ limit.label }}</span>
                        <span class="font-mono text-cyan-300">{{ settings.handling[name] }}{{ limit.unit === '×' ? '×' : ` ${limit.unit}` }}</span>
                    </span>
                    <input
                        v-model.number="settings.handling[name]"
                        type="range"
                        :min="limit.min"
                        :max="limit.max"
                        :step="limit.step"
                        class="mt-1 w-full accent-cyan-400"
                        :data-testid="`handling-${name}`"
                        @change="saveSettings"
                    />
                    <span class="text-xs text-slate-500">{{ limit.hint }}</span>
                </label>
            </div>
            <p class="mt-2 text-xs text-slate-500">Changes apply from your next game.</p>
        </section>

        <button type="button" class="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800" @click="resetSettings">
            Reset to defaults
        </button>
    </div>
</template>
