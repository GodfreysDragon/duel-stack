import { serialize } from './board.js';
import { mergePendingGarbage } from './simulation.js';

const RETRY_AFTER_MS = 600;

/**
 * Keeps a client's optimistic game in step with the server: locks are POSTed one at a time, in order, and the
 * server's answer either confirms the predicted board or brings a state to resync to. Also reconciles incoming
 * garbage, whose broadcasts can race the lock responses.
 *
 * No Vue and no network of its own: `post(body)` sends one lock and resolves with the server's response, or throws
 * an error with `status` and `data` (409 = rejected, anything else is retried).
 */
export class LockSync {
    /**
     * @param {object} options
     * @param {(body: object) => Promise<object>} options.post
     * @param {() => object} options.game the current Game (its pendingGarbage is read and replaced)
     * @param {(state: object, pendingGarbage: object[], reason: string) => void} options.onResync
     * @param {() => void} options.onMatchOver this player topped out and the server agreed
     * @param {() => void} options.onMatchClosed the server says the match was already over
     * @param {() => void} [options.onProgress] after each answer from the server (`sent` may have changed)
     * @param {(ms: number) => Promise<void>} [options.wait]
     */
    constructor({ post, game, onResync, onMatchOver, onMatchClosed, onProgress = () => {}, wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)) }) {
        Object.assign(this, { post, game, onResync, onMatchOver, onMatchClosed, onProgress, wait });
        this.queue = []; // [{ body, sent, attack }]: locks waiting to be POSTed, with the garbage each sends and its attack
        this.expectedBoards = new Map();
        this.sending = false;
        this.stopped = false;
        this.confirmedSent = 0;
        // The newest garbage batch the server's last pending list accounted for: a later garbage.sent broadcast for
        // it is stale (the batch may have been cancelled outright meanwhile).
        this.garbageThrough = 0;
        // Batches that arrived while a lock that attacks was unconfirmed: the server may have used that attack to
        // cancel them, so they wait for its pending list (garbage has a grace period, so this is safe).
        this.deferredGarbage = [];
    }

    /** Garbage the server confirmed, plus what the locks still in flight should send. */
    get sent() {
        return this.confirmedSent + this.queue.reduce((sum, item) => sum + item.sent, 0);
    }

    /** Queues a lock the local game made, with the outcome it predicted, and sends it when its turn comes. */
    push(lock, outcome) {
        const pieceNumber = outcome.state.pieces - 1;
        this.expectedBoards.set(pieceNumber, serialize(outcome.state.board));
        this.queue.push({
            body: {
                piece_number: pieceNumber,
                piece: lock.piece,
                rotation: lock.rotation,
                x: lock.x,
                y: lock.y,
                held: lock.held,
                garbage_ids: lock.garbageIds,
            },
            sent: outcome.sent,
            attack: outcome.attack,
        });
        return this.pump();
    }

    /**
     * An incoming garbage.sent batch. Returns false when it is stale and was dropped.
     */
    receive(batch) {
        if (batch.id <= this.garbageThrough) return false;
        if (this.queue.some((item) => item.attack > 0)) this.deferredGarbage.push(batch);
        else this.game().receiveGarbage(batch);
        return true;
    }

    /** Stops sending: the match is over. */
    stop() {
        this.stopped = true;
        this.queue = [];
    }

    async pump() {
        if (this.sending || this.queue.length === 0 || this.stopped) return;
        this.sending = true;
        const item = this.queue[0];
        try {
            const response = await this.post(item.body);
            this.queue.shift();
            this.confirmedSent += response.sent?.lines ?? 0;
            const expected = this.expectedBoards.get(item.body.piece_number);
            this.expectedBoards.delete(item.body.piece_number);
            if (this.queue.length === 0 && !response.match_over) {
                const pending = this.reconcile(response);
                if (response.state.board !== expected) this.onResync(response.state, pending, `board differs after piece #${item.body.piece_number}`);
                else this.game().pendingGarbage = pending;
            }
            if (response.match_over) this.onMatchOver();
        } catch (e) {
            if (e?.status === 409) {
                this.queue = [];
                this.expectedBoards.clear();
                if (e.data?.message === 'The match is over.') {
                    this.onMatchClosed();
                } else if (e.data?.state) {
                    this.onResync(e.data.state, this.reconcile(e.data), `lock rejected: ${e.data.message}`);
                }
            } else {
                // Network trouble: keep the queue and retry. A lock that did land
                // comes back as a 409 "expected piece #n" and triggers a resync.
                await this.wait(RETRY_AFTER_MS);
            }
        } finally {
            this.sending = false;
            this.onProgress();
        }
        return this.pump();
    }

    /** The server's pending garbage, plus local and deferred batches newer than anything it accounted for. */
    reconcile({ pending_garbage: server, garbage_through: through }) {
        this.garbageThrough = Math.max(this.garbageThrough, through);
        const pending = mergePendingGarbage(server, [...this.game().pendingGarbage, ...this.deferredGarbage], this.garbageThrough);
        this.deferredGarbage = [];
        return pending;
    }
}
