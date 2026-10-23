import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_SETTINGS, normalizeSettings } from '../../resources/js/lib/settings.js';
import { Game } from '../../resources/js/engine/game.js';

test('missing or broken saved settings fall back to the defaults', () => {
    assert.deepEqual(normalizeSettings(null), DEFAULT_SETTINGS);
    assert.deepEqual(normalizeSettings({ bindings: 'nope', handling: { das: 'fast' } }), DEFAULT_SETTINGS);
});

test('saved handling is rounded and clamped to its limits', () => {
    const { handling } = normalizeSettings({ handling: { das: 999, arr: -5, softDrop: 12.6 } });
    assert.deepEqual(handling, { das: 400, arr: 0, softDrop: 13 });
});

test('a key bound to two actions keeps only the first', () => {
    const { bindings } = normalizeSettings({ bindings: { left: ['KeyJ'], right: ['KeyJ', 'KeyL'] } });
    assert.deepEqual(bindings.left, ['KeyJ']);
    assert.deepEqual(bindings.right, ['KeyL']);
    assert.deepEqual(bindings.hold, DEFAULT_SETTINGS.bindings.hold, 'unsaved actions keep their defaults');
});

test('a game uses the DAS and ARR it is given: ARR 0 slides to the wall once DAS is up', () => {
    const game = new Game({ seed: 0, handling: { das: 100, arr: 0 } });
    game.start();
    const startX = game.active.x;

    game.press('right');
    assert.equal(game.active.x, startX + 1, 'one step on the press');
    game.update(99);
    assert.equal(game.active.x, startX + 1, 'nothing more before DAS');
    game.update(2);
    assert.ok(game.active.x > startX + 2, `slid to the wall (x = ${game.active.x})`);
});
