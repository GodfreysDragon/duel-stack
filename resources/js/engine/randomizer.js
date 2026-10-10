import { PIECE_TYPES } from './constants.js';

const MOD = 4294967296; // 2^32

/**
 * Seeded 7-bag piece sequence, identical to app/Game/Randomizer.php.
 *
 * Uses a 32-bit LCG whose intermediate products stay below 2^53, so JS
 * doubles and PHP 64-bit ints compute exactly the same numbers.
 */
export class Randomizer {
    constructor(seed) {
        this.state = ((seed % MOD) + MOD) % MOD;
        this.pieces = [];
    }

    next() {
        this.state = (this.state * 1664525 + 1013904223) % MOD;
        return this.state;
    }

    refill() {
        const bag = [...PIECE_TYPES];
        for (let i = bag.length - 1; i > 0; i--) {
            const j = Math.floor((this.next() * (i + 1)) / MOD);
            [bag[i], bag[j]] = [bag[j], bag[i]];
        }
        this.pieces.push(...bag);
    }

    /** The piece at a 0-based position in the sequence. */
    piece(index) {
        while (this.pieces.length <= index) this.refill();
        return this.pieces[index];
    }
}
