<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Application } from 'pixi.js';
import { BoardView } from '../render/BoardView';

/**
 * Hosts the PixiJS canvas: the local board with hold/next panels and a
 * smaller opponent board. `frame(dtMs)` is called on every tick and returns
 * { me, opponent } views for BoardView.update(). The canvas shrinks to fit the width it is given, and with
 * `fit` on a touch screen the height too (the parent must then have a height: see fitScreen in app.js).
 *
 * The `under-hold` slot sits in the empty column below the HOLD panel, scaled with the canvas.
 *
 * With `fit` on a touch screen it emits `aspect` (canvas width / height) once known, so the parent can size its
 * box to the board and leave the rest of the screen to the buttons.
 */
const props = defineProps({
    frame: { type: Function, required: true },
    fit: { type: Boolean, default: false },
});
const emit = defineEmits(['aspect']);

const COMPACT_BELOW_PX = 640;
const MAX_FIT_SCALE = 1.4;

const host = ref(null);
const stage = ref(null);
const underHoldStyle = ref(null);
const fitHeight = props.fit && window.matchMedia('(pointer: coarse)').matches;
let app = null;
let resizer = null;
let destroyed = false;

onMounted(async () => {
    const pixi = new Application();
    await pixi.init({
        backgroundAlpha: 0,
        antialias: true,
        resolution: Math.min(window.devicePixelRatio || 1, 2),
        autoDensity: true,
        width: 10,
        height: 10,
    });
    if (destroyed) return pixi.destroy(true);
    app = pixi;

    const me = new BoardView({ cell: 28, panels: true });
    // On narrow screens the opponent's board tucks under the NEXT panel, so the player's own board gets the width.
    const compact = host.value.clientWidth < COMPACT_BELOW_PX;
    const opponent = new BoardView({ cell: compact ? Math.floor((me.panelW - 8) / 10) : 17, panels: false });
    if (compact) {
        opponent.position.set(me.nextX + me.panelW - opponent.totalW, me.totalH - opponent.totalH);
    } else {
        opponent.position.set(me.totalW + 28, me.totalH - opponent.totalH);
    }
    app.stage.addChild(me, opponent);
    const width = Math.max(me.totalW, opponent.x + opponent.totalW) + 4;
    const height = me.totalH + 4;
    app.renderer.resize(width, height);
    app.stage.position.set(2, 2);
    if (fitHeight) emit('aspect', width / height);

    app.canvas.classList.add('block');
    app.canvas.dataset.testid = 'game-canvas';
    stage.value.prepend(app.canvas);

    // In percent of the canvas, so the slot follows it as it scales.
    const percent = (px, of) => `${(px / of) * 100}%`;
    underHoldStyle.value = {
        left: percent(2, width),
        top: percent(2 + me.cell * 4, height),
        width: percent(me.panelW, width),
    };

    // Smaller when the space is. Larger only to fill a tall phone screen, by no more than the canvas resolution allows.
    const maxScale = fitHeight ? MAX_FIT_SCALE : 1;
    const fitCanvas = () => {
        const scale = Math.min(maxScale, host.value.clientWidth / width, fitHeight ? host.value.clientHeight / height : 1);
        app.canvas.style.width = `${Math.floor(width * scale)}px`;
        app.canvas.style.height = `${Math.floor(height * scale)}px`;
    };
    fitCanvas();
    resizer = new ResizeObserver(fitCanvas);
    resizer.observe(host.value);

    app.ticker.add((ticker) => {
        const view = props.frame(ticker.deltaMS);
        me.update(view.me);
        opponent.update(view.opponent);
    });
});

onBeforeUnmount(() => {
    destroyed = true;
    resizer?.disconnect();
    app?.destroy(true, { children: true });
});
</script>

<template>
    <div ref="host" class="flex justify-center" :class="{ 'h-full': fitHeight }">
        <div ref="stage" class="relative self-start">
            <div v-if="underHoldStyle && $slots['under-hold']" class="absolute" :style="underHoldStyle">
                <slot name="under-hold" />
            </div>
        </div>
    </div>
</template>
