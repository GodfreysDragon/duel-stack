import { Container, Graphics, Text } from 'pixi.js';
import { HIDDEN_ROWS, HEIGHT, SHAPES, WIDTH } from '../engine/constants.js';
import { cellsOf } from '../engine/board.js';

export const COLORS = {
    I: 0x22d3ee,
    J: 0x3b82f6,
    L: 0xf97316,
    O: 0xfacc15,
    S: 0x22c55e,
    T: 0xa855f7,
    Z: 0xef4444,
    G: 0x64748b,
};

const VISIBLE_ROWS = HEIGHT - HIDDEN_ROWS;
const FLASH_MS = 220;

/**
 * One player's playfield drawn with PixiJS: the stack, the falling piece and
 * its ghost, hold and next panels, an incoming-garbage meter and a line-clear
 * flash. Pure view: call update() with plain state every frame.
 */
export class BoardView extends Container {
    constructor({ cell = 28, panels = true } = {}) {
        super();
        this.cell = cell;
        this.panels = panels;

        const gap = Math.round(cell * 0.4);
        this.panelW = panels ? cell * 4.4 : 0;
        this.meterW = Math.max(6, Math.round(cell * 0.35));
        this.boardX = (panels ? this.panelW + gap : 0) + this.meterW + 2;
        this.boardW = WIDTH * cell;
        this.boardH = VISIBLE_ROWS * cell;
        this.nextX = this.boardX + this.boardW + gap;
        this.totalW = this.boardX + this.boardW + (panels ? gap + this.panelW : 0);
        this.totalH = this.boardH;

        this.addChild(this.drawFrame());
        this.dynamic = new Graphics();
        this.addChild(this.dynamic);
    }

    drawFrame() {
        const { cell, boardX, boardW, boardH } = this;
        const frame = new Container();
        const g = new Graphics();

        g.rect(boardX - 2, -2, boardW + 4, boardH + 4).fill(0x1e293b);
        g.rect(boardX, 0, boardW, boardH).fill(0x0b1120);
        for (let x = 1; x < WIDTH; x++) g.rect(boardX + x * cell, 0, 1, boardH).fill({ color: 0x1e293b, alpha: 0.7 });
        for (let y = 1; y < VISIBLE_ROWS; y++) g.rect(boardX, y * cell, boardW, 1).fill({ color: 0x1e293b, alpha: 0.7 });
        g.rect(boardX - this.meterW - 2, 0, this.meterW, boardH).fill(0x111827);

        if (this.panels) {
            g.roundRect(0, 0, this.panelW, cell * 3.6, 8).fill(0x111827);
            g.roundRect(this.nextX, 0, this.panelW, cell * 11.5, 8).fill(0x111827);
            const style = { fontFamily: 'Instrument Sans, sans-serif', fontSize: Math.round(cell * 0.45), fill: 0x94a3b8, fontWeight: '600', letterSpacing: 2 };
            const hold = new Text({ text: 'HOLD', style });
            hold.position.set(10, 6);
            const next = new Text({ text: 'NEXT', style });
            next.position.set(this.nextX + 10, 6);
            frame.addChild(g, hold, next);
        } else {
            frame.addChild(g);
        }
        return frame;
    }

    block(g, x, y, size, color, alpha = 1) {
        g.rect(x, y, size, size).fill({ color, alpha });
        g.rect(x, y, size, Math.max(2, size * 0.14)).fill({ color: 0xffffff, alpha: 0.22 * alpha });
        g.rect(x, y + size - Math.max(2, size * 0.14), size, Math.max(2, size * 0.14)).fill({ color: 0x000000, alpha: 0.25 * alpha });
    }

    boardCell(g, col, row, color, alpha = 1) {
        if (row < HIDDEN_ROWS) return;
        const { cell, boardX } = this;
        this.block(g, boardX + col * cell + 1, (row - HIDDEN_ROWS) * cell + 1, cell - 2, color, alpha);
    }

    drawPreview(g, type, cx, cy, size, alpha = 1) {
        const cells = SHAPES[type][0];
        const xs = cells.map(([x]) => x);
        const ys = cells.map(([, y]) => y);
        const w = (Math.max(...xs) - Math.min(...xs) + 1) * size;
        const h = (Math.max(...ys) - Math.min(...ys) + 1) * size;
        for (const [x, y] of cells) {
            this.block(g, cx - w / 2 + (x - Math.min(...xs)) * size, cy - h / 2 + (y - Math.min(...ys)) * size, size - 1, COLORS[type], alpha);
        }
    }

    /**
     * @param view { board: string[], active?, ghostY?, hold?, holdLocked?, next?: string[],
     *               pending?: number, clear?: { rows, at }, toppedOut?: boolean }
     */
    update(view) {
        const g = this.dynamic;
        const { cell, boardX, boardH } = this;
        g.clear();

        view.board.forEach((row, y) => {
            for (let x = 0; x < WIDTH; x++) {
                if (row[x] !== '.') this.boardCell(g, x, y, COLORS[row[x]]);
            }
        });

        if (view.active) {
            const { type, rotation, x, y } = view.active;
            if (view.ghostY !== undefined && view.ghostY !== y) {
                for (const [cx, cy] of cellsOf(type, rotation, x, view.ghostY)) this.boardCell(g, cx, cy, COLORS[type], 0.22);
            }
            for (const [cx, cy] of cellsOf(type, rotation, x, y)) this.boardCell(g, cx, cy, COLORS[type]);
        }

        if (view.clear && performance.now() - view.clear.at < FLASH_MS) {
            const alpha = 1 - (performance.now() - view.clear.at) / FLASH_MS;
            for (const row of view.clear.rows) {
                if (row >= HIDDEN_ROWS) g.rect(boardX, (row - HIDDEN_ROWS) * cell, this.boardW, cell).fill({ color: 0xffffff, alpha: 0.75 * alpha });
            }
        }

        const pending = Math.min(view.pending ?? 0, VISIBLE_ROWS);
        if (pending > 0) {
            g.rect(boardX - this.meterW - 2, boardH - pending * cell, this.meterW, pending * cell).fill(0xef4444);
        }

        if (this.panels) {
            const size = Math.round(cell * 0.7);
            if (view.hold) this.drawPreview(g, view.hold, this.panelW / 2, cell * 2.1, size, view.holdLocked ? 0.35 : 1);
            (view.next ?? []).forEach((type, i) => {
                this.drawPreview(g, type, this.nextX + this.panelW / 2, cell * 2.1 + i * cell * 2.1, i === 0 ? size : Math.round(size * 0.85));
            });
        }

        if (view.toppedOut) g.rect(boardX, 0, this.boardW, boardH).fill({ color: 0x020617, alpha: 0.6 });
    }
}
