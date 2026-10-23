import { reactive } from 'vue';
import { DEFAULT_HANDLING } from '../engine/game.js';

/**
 * Key bindings and input handling, kept in this browser: keyboards and preferences differ from device
 * to device, so they are not stored with the account.
 */

const STORAGE_KEY = 'stack-duel:settings';

export const ACTIONS = [
    ['left', 'Move left'],
    ['right', 'Move right'],
    ['down', 'Soft drop'],
    ['hardDrop', 'Hard drop'],
    ['rotateCW', 'Rotate right'],
    ['rotateCCW', 'Rotate left'],
    ['rotate180', 'Rotate 180°'],
    ['hold', 'Hold'],
];

export const HANDLING_LIMITS = {
    das: { min: 0, max: 400, step: 1, label: 'DAS', unit: 'ms', hint: 'Delay before a held direction starts repeating.' },
    arr: { min: 0, max: 100, step: 1, label: 'ARR', unit: 'ms', hint: 'Time between repeats. 0 slides straight to the wall.' },
    softDrop: { min: 2, max: 60, step: 1, label: 'Soft drop', unit: '×', hint: 'Soft-drop speed, as a multiple of gravity.' },
};

export const DEFAULT_SETTINGS = {
    bindings: {
        left: ['ArrowLeft'],
        right: ['ArrowRight'],
        down: ['ArrowDown'],
        hardDrop: ['Space'],
        rotateCW: ['ArrowUp', 'KeyX'],
        rotateCCW: ['KeyZ', 'ControlLeft'],
        rotate180: ['KeyA'],
        hold: ['KeyC', 'ShiftLeft'],
    },
    handling: { ...DEFAULT_HANDLING },
};

/**
 * Fills in defaults and drops anything invalid, so a stale or hand-edited saved copy can't break input.
 * A key bound to more than one action keeps only its first.
 */
export function normalizeSettings(raw) {
    const taken = new Set();
    const bindings = {};
    for (const [action] of ACTIONS) {
        const saved = raw?.bindings?.[action];
        const keys = Array.isArray(saved) ? saved.filter((k) => typeof k === 'string' && k !== '') : DEFAULT_SETTINGS.bindings[action];
        bindings[action] = keys.filter((key) => !taken.has(key) && taken.add(key));
    }

    const handling = {};
    for (const [name, { min, max }] of Object.entries(HANDLING_LIMITS)) {
        const value = Number(raw?.handling?.[name]);
        handling[name] = Number.isFinite(value) ? Math.min(Math.max(Math.round(value), min), max) : DEFAULT_HANDLING[name];
    }

    return { bindings, handling };
}

function load() {
    try {
        return normalizeSettings(JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null'));
    } catch {
        return normalizeSettings(null);
    }
}

export const settings = reactive(load());

export function saveSettings() {
    Object.assign(settings, normalizeSettings(settings));
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Private mode or blocked storage: the settings last until the page is closed.
    }
}

export function resetSettings() {
    Object.assign(settings, normalizeSettings(null));
    saveSettings();
}

/** The action a key code is bound to, if any. */
export function actionFor(code) {
    return ACTIONS.find(([action]) => settings.bindings[action].includes(code))?.[0] ?? null;
}

/** Binds a key to an action, taking it away from any other action first. */
export function bindKey(action, code) {
    for (const [other] of ACTIONS) {
        settings.bindings[other] = settings.bindings[other].filter((key) => key !== code);
    }
    settings.bindings[action].push(code);
    saveSettings();
}

export function unbindKey(action, code) {
    settings.bindings[action] = settings.bindings[action].filter((key) => key !== code);
    saveSettings();
}

const KEY_LABELS = {
    ArrowLeft: '←',
    ArrowRight: '→',
    ArrowUp: '↑',
    ArrowDown: '↓',
    Space: 'Space',
    ShiftLeft: 'Shift',
    ShiftRight: 'Right Shift',
    ControlLeft: 'Ctrl',
    ControlRight: 'Right Ctrl',
    AltLeft: 'Alt',
    AltRight: 'Right Alt',
};

/** A short, readable name for a KeyboardEvent.code. */
export function keyLabel(code) {
    return KEY_LABELS[code] ?? code.replace(/^Key/, '').replace(/^Digit/, '').replace(/^Numpad/, 'Num ');
}
