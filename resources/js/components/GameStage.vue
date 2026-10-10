<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Application } from 'pixi.js';
import { BoardView } from '../render/BoardView';

/**
 * Hosts the PixiJS canvas: the local board with hold/next panels and a
 * smaller opponent board. `frame(dtMs)` is called on every tick and returns
 * { me, opponent } views for BoardView.update().
 */
const props = defineProps({
    frame: { type: Function, required: true },
});

const COMPACT_BELOW_PX = 640;

const host = ref(null);
let app = null;
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
    app.renderer.resize(Math.max(me.totalW, opponent.x + opponent.totalW) + 4, me.totalH + 4);
    app.stage.position.set(2, 2);

    // autoDensity sets an inline pixel height, which would squash the canvas when max-w-full narrows it.
    app.canvas.style.removeProperty('height');
    app.canvas.classList.add('block', 'h-auto', 'max-w-full');
    app.canvas.dataset.testid = 'game-canvas';
    host.value.appendChild(app.canvas);

    app.ticker.add((ticker) => {
        const view = props.frame(ticker.deltaMS);
        me.update(view.me);
        opponent.update(view.opponent);
    });
});

onBeforeUnmount(() => {
    destroyed = true;
    app?.destroy(true, { children: true });
});
</script>

<template>
    <div ref="host" class="flex justify-center"></div>
</template>
