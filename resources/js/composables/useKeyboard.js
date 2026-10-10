import { onBeforeUnmount, onMounted } from 'vue';

export const KEY_BINDINGS = {
    ArrowLeft: 'left',
    ArrowRight: 'right',
    ArrowDown: 'down',
    ArrowUp: 'rotateCW',
    KeyX: 'rotateCW',
    KeyZ: 'rotateCCW',
    ControlLeft: 'rotateCCW',
    KeyA: 'rotate180',
    KeyC: 'hold',
    ShiftLeft: 'hold',
    Space: 'hardDrop',
};

/** Routes keyboard input to whichever Game `getGame()` returns. */
export function useKeyboard(getGame) {
    const down = (event) => {
        const action = KEY_BINDINGS[event.code];
        if (!action || event.target.closest?.('input, textarea, select')) return;
        event.preventDefault();
        if (!event.repeat) getGame()?.press(action);
    };
    const up = (event) => {
        const action = KEY_BINDINGS[event.code];
        if (action) getGame()?.release(action);
    };

    onMounted(() => {
        window.addEventListener('keydown', down);
        window.addEventListener('keyup', up);
    });
    onBeforeUnmount(() => {
        window.removeEventListener('keydown', down);
        window.removeEventListener('keyup', up);
    });
}
