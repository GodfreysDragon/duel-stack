import { onBeforeUnmount, onMounted } from 'vue';
import { actionFor } from '../lib/settings';

/** Routes keyboard input to whichever Game `getGame()` returns, using the player's key bindings. */
export function useKeyboard(getGame) {
    const down = (event) => {
        const action = actionFor(event.code);
        if (!action || event.target.closest?.('input, textarea, select')) return;
        event.preventDefault();
        if (!event.repeat) getGame()?.press(action);
    };
    const up = (event) => {
        const action = actionFor(event.code);
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
