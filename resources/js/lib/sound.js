import { reactive } from 'vue';

/**
 * Small synthesized sound effects (Web Audio, no asset files). The audio context is created on
 * first use, which browsers only allow after a user gesture; until then every sound is a no-op.
 */
const STORAGE_KEY = 'stack-duel:muted';

export const sound = reactive({ muted: readMuted() });

let context = null;

function readMuted() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export function toggleMuted() {
    sound.muted = !sound.muted;
    try {
        window.localStorage.setItem(STORAGE_KEY, sound.muted ? '1' : '0');
    } catch {
        // Private mode or blocked storage: the setting just won't persist.
    }
}

function audio() {
    if (sound.muted || typeof window.AudioContext === 'undefined') return null;
    context ??= new window.AudioContext();
    if (context.state === 'suspended') context.resume();
    return context;
}

/** One enveloped oscillator note: frequency in Hz, start offset and length in seconds. */
function tone(frequency, { at = 0, length = 0.08, type = 'square', volume = 0.06, slideTo = null } = {}) {
    const ctx = audio();
    if (!ctx) return;
    const start = ctx.currentTime + at;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(frequency, start);
    if (slideTo) osc.frequency.exponentialRampToValueAtTime(slideTo, start + length);
    gain.gain.setValueAtTime(volume, start);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
    osc.connect(gain).connect(ctx.destination);
    osc.start(start);
    osc.stop(start + length + 0.02);
}

const CLEAR_NOTES = [523.25, 659.25, 783.99, 1046.5]; // C5 E5 G5 C6

export const sfx = {
    /** A placed piece; brighter for line clears, an arpeggio for a tetris. */
    lock(linesCleared) {
        if (linesCleared === 0) return tone(140, { length: 0.05, type: 'triangle', volume: 0.08 });
        CLEAR_NOTES.slice(0, linesCleared).forEach((note, i) => tone(note, { at: i * 0.05, length: 0.12, volume: 0.05 }));
    },
    /** Garbage on its way to this player. */
    incoming() {
        tone(110, { length: 0.18, type: 'sawtooth', volume: 0.05, slideTo: 70 });
    },
    countdownTick(final = false) {
        tone(final ? 880 : 440, { length: final ? 0.25 : 0.1, type: 'sine', volume: 0.08 });
    },
    win() {
        [523.25, 659.25, 783.99, 1046.5].forEach((note, i) => tone(note, { at: i * 0.09, length: 0.2, type: 'triangle', volume: 0.07 }));
    },
    lose() {
        tone(330, { length: 0.6, type: 'triangle', volume: 0.07, slideTo: 110 });
    },
};
