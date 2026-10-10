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
    const opponent = new BoardView({ cell: 17, panels: false });
    opponent.position.set(me.totalW + 28, me.totalH - opponent.totalH);
    app.stage.addChild(me, opponent);
    app.renderer.resize(opponent.x + opponent.totalW + 4, me.totalH + 4);
    app.stage.position.set(2, 2);

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
