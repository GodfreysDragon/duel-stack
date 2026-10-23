import { SPAWN_X, SPAWN_Y, kicksFor } from './constants.js';
import { dropY, fits, isGrounded } from './board.js';
import { Randomizer } from './randomizer.js';
import { afterHold, applyLock, cancelGarbage, initialState, preview } from './simulation.js';

const LOCK_DELAY_MS = 500;
const MAX_LOCK_RESETS = 15;

/**
 * Input handling a player can tune: DAS (ms before a held direction auto-repeats), ARR (ms between repeats;
 * 0 moves straight to the wall) and the soft-drop speed as a multiple of gravity.
 */
export const DEFAULT_HANDLING = { das: 133, arr: 33, softDrop: 20 };

/** Gravity in rows per second; ramps up every 30 seconds so matches end. */
export function gravityAt(elapsedMs) {
    return Math.min(1 + Math.floor(elapsedMs / 30000) * 0.75, 20);
}

/**
 * Real-time single-player controller: owns the falling piece, input timing,
 * gravity and lock delay, and turns each lock into a call to applyLock().
 * Rendering and networking listen through the callbacks.
 */
export class Game {
    constructor({ seed, onLock = () => {}, onTopOut = () => {}, handling = {} }) {
        this.handling = { ...DEFAULT_HANDLING, ...handling };
        this.randomizer = new Randomizer(seed);
        this.state = initialState(this.randomizer);
        this.onLock = onLock;
        this.onTopOut = onTopOut;
        this.pendingGarbage = []; // [{ id, lines, hole }]
        this.elapsed = 0;
        this.running = false;
        this.held = { left: false, right: false, down: false };
        this.lastClear = null; // { rows, at } for the renderer's flash
        this.spawn(false);
    }

    start() {
        this.running = true;
    }

    stop() {
        this.running = false;
    }

    spawn(heldThisTurn) {
        this.active = { type: this.state.current, rotation: 0, x: SPAWN_X, y: SPAWN_Y };
        this.heldThisTurn = heldThisTurn;
        this.gravityAcc = 0;
        this.lockTimer = 0;
        this.lockResets = 0;
        this.lowestY = this.active.y;
        // Spawning one row lower when there is room keeps the piece visible.
        if (fits(this.state.board, this.active.type, 0, SPAWN_X, SPAWN_Y + 1)) this.active.y++;
    }

    get preview() {
        return preview(this.state, this.randomizer, 5);
    }

    get ghostY() {
        const { type, rotation, x, y } = this.active;
        return dropY(this.state.board, type, rotation, x, y);
    }

    get pendingLines() {
        return this.pendingGarbage.reduce((sum, batch) => sum + batch.lines, 0);
    }

    receiveGarbage(batch) {
        if (!this.pendingGarbage.some((b) => b.id === batch.id)) this.pendingGarbage.push(batch);
    }

    // ---- input ------------------------------------------------------------

    press(action) {
        if (!this.running) return;
        switch (action) {
            case 'left':
            case 'right':
                this.held[action] = true;
                this.dasDir = action;
                this.dasTimer = 0;
                this.arrTimer = 0;
                this.shift(action === 'left' ? -1 : 1);
                break;
            case 'down':
                this.held.down = true;
                break;
            case 'rotateCW':
                this.rotate(1);
                break;
            case 'rotateCCW':
                this.rotate(-1);
                break;
            case 'rotate180':
                this.rotate(1) && this.rotate(1);
                break;
            case 'hold':
                this.hold();
                break;
            case 'hardDrop':
                this.hardDrop();
                break;
        }
    }

    release(action) {
        if (action in this.held) this.held[action] = false;
        if (action === this.dasDir) {
            const other = action === 'left' ? 'right' : 'left';
            this.dasDir = this.held[other] ? other : null;
            this.dasTimer = 0;
        }
    }

    shift(dx) {
        const { type, rotation, x, y } = this.active;
        if (!fits(this.state.board, type, rotation, x + dx, y)) return false;
        this.active.x += dx;
        this.onMoved();
        return true;
    }

    rotate(dir) {
        const { type, rotation, x, y } = this.active;
        const to = (rotation + dir + 4) % 4;
        for (const [kx, ky] of kicksFor(type, rotation, to)) {
            if (fits(this.state.board, type, to, x + kx, y + ky)) {
                Object.assign(this.active, { rotation: to, x: x + kx, y: y + ky });
                this.onMoved();
                return true;
            }
        }
        return false;
    }

    /** Moving or rotating on the ground resets the lock timer, a limited number of times. */
    onMoved() {
        if (this.lockResets < MAX_LOCK_RESETS && this.lockTimer > 0) {
            this.lockTimer = 0;
            this.lockResets++;
        }
    }

    hold() {
        if (this.heldThisTurn) return;
        const swapped = afterHold(this.state, this.randomizer);
        // A hold that would spawn into the stack is ignored; only locks can top a player out.
        if (!fits(this.state.board, swapped.current, 0, SPAWN_X, SPAWN_Y)) return;
        // Keep the turn's starting state: the lock is validated from it, as on the server.
        this.turnStartState = { ...this.state };
        Object.assign(this.state, swapped);
        this.spawn(true);
    }

    hardDrop() {
        this.active.y = this.ghostY;
        this.lock();
    }

    // ---- simulation ---------------------------------------------------------

    update(dt) {
        if (!this.running) return;
        this.elapsed += dt;

        if (this.dasDir) {
            this.dasTimer += dt;
            if (this.dasTimer >= this.handling.das) {
                this.arrTimer += dt;
                while (this.arrTimer >= this.handling.arr) {
                    this.arrTimer -= this.handling.arr;
                    if (!this.shift(this.dasDir === 'left' ? -1 : 1)) break;
                }
            }
        }

        const gravity = gravityAt(this.elapsed) * (this.held.down ? this.handling.softDrop : 1);
        this.gravityAcc += (gravity * dt) / 1000;
        while (this.gravityAcc >= 1) {
            this.gravityAcc -= 1;
            const { type, rotation, x, y } = this.active;
            if (!fits(this.state.board, type, rotation, x, y + 1)) {
                this.gravityAcc = 0;
                break;
            }
            this.active.y++;
            if (this.active.y > this.lowestY) {
                this.lowestY = this.active.y;
                this.lockResets = 0;
            }
        }

        const { type, rotation, x, y } = this.active;
        if (isGrounded(this.state.board, type, rotation, x, y)) {
            this.lockTimer += dt;
            if (this.lockTimer >= LOCK_DELAY_MS) this.lock();
        } else {
            this.lockTimer = 0;
        }
    }

    lock() {
        const { type, rotation, x, y } = this.active;
        const base = { piece: type, rotation, x, y, held: this.heldThisTurn };
        // The hold swap already happened locally, so replay it from the turn's start state.
        const turnStart = this.heldThisTurn ? this.turnStartState : this.state;

        let result = applyLock(turnStart, this.randomizer, { ...base, garbage: [] });
        let applied = [];
        if (result.cleared.length === 0 && this.pendingGarbage.length > 0) {
            applied = this.pendingGarbage;
            this.pendingGarbage = [];
            result = applyLock(turnStart, this.randomizer, { ...base, garbage: applied });
        }

        // An attack cancels incoming garbage first; `sent` is what reaches the opponent.
        const { pending, sent } = cancelGarbage(this.pendingGarbage, result.attack);
        this.pendingGarbage = pending;

        this.state = result.state;
        this.turnStartState = null;
        if (result.cleared.length) this.lastClear = { rows: result.cleared, at: performance.now() };
        this.onLock({ ...base, garbageIds: applied.map((b) => b.id) }, { ...result, sent });

        if (this.state.toppedOut) return this.topOut();
        this.spawn(false);
    }

    topOut() {
        this.state.toppedOut = true;
        this.running = false;
        this.onTopOut();
    }

    /** Replaces local state with the server's view (after a rejected lock). */
    resync(state, pendingGarbage) {
        this.state = { ...state, toppedOut: false };
        this.pendingGarbage = pendingGarbage;
        this.turnStartState = null;
        this.spawn(false);
    }

    /** Teleports the active piece (bot/autopilot); performs the hold first if asked. */
    placeDirect({ held, rotation, x }) {
        if (!this.running) return;
        if (held && !this.heldThisTurn) this.hold();
        if (!this.running) return;
        const { type } = this.active;
        if (!fits(this.state.board, type, rotation, x, SPAWN_Y)) return this.hardDrop();
        Object.assign(this.active, { rotation, x, y: SPAWN_Y });
        this.hardDrop();
    }
}
