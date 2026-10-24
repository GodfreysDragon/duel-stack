/**
 * Two-browser end-to-end check of a full ranked match against a running app + Reverb.
 *
 *   VITE_E2E_AUTOPILOT=true npm run build
 *   php artisan reverb:start
 *   php artisan bots:play
 *   php artisan serve --port=8040
 *   E2E_URL=http://127.0.0.1:8040 npm run test:e2e
 *
 * Both players register through the UI and queue, and the bot autopilot
 * (?autopilot=1, only in dev builds or builds made with VITE_E2E_AUTOPILOT=true)
 * plays each side of a real match: every lock goes through the
 * server's validator, and boards and garbage cross over Reverb. Player B then
 * reloads mid-match (resuming from server state) and stacks pieces by keyboard
 * until topping out. The test then checks the result, the rating changes,
 * the profile, the leaderboard and the replay.
 * Uses an installed Edge/Chrome via playwright-core; screenshots go to storage/e2e.
 */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright-core';

const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8040';
const OUT = 'storage/e2e';
const BOT_PHASE_MS = Number(process.env.E2E_BOT_PHASE_MS ?? 25000);
mkdirSync(OUT, { recursive: true });

const results = [];
let failed = false;
async function step(name, fn) {
    const started = Date.now();
    try {
        const note = await fn();
        results.push(`PASS ${name} (${Date.now() - started}ms)${note ? ` - ${note}` : ''}`);
    } catch (e) {
        failed = true;
        results.push(`FAIL ${name}: ${e.message.split('\n')[0]}`);
    }
}
function expect(condition, message) {
    if (!condition) throw new Error(message);
}

const browser = await chromium.launch({ channel: process.env.E2E_BROWSER ?? 'msedge', headless: true });
const pages = {};
for (const who of ['A', 'B']) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await context.newPage();
    page.on('pageerror', (e) => {
        failed = true;
        results.push(`PAGE ERROR ${who}: ${e.message}`);
    });
    page.on('dialog', (d) => d.accept());
    pages[who] = page;
}
const { A: a, B: b } = pages;
const suffix = Date.now().toString(36).slice(-5);
const names = { A: `ada_${suffix}`, B: `bob_${suffix}` };

const apiGet = (page, url) => page.evaluate(async (u) => (await fetch(u, { headers: { Accept: 'application/json' } })).json(), url);
const status = (page) => page.getAttribute('[data-testid=match-view]', 'data-status');
const waitForStatus = (page, wanted, timeout = 20000) =>
    page.waitForSelector(`[data-testid=match-view][data-status="${wanted}"]`, { timeout });

await step('both players register through the UI', async () => {
    for (const [who, page] of Object.entries(pages)) {
        await page.goto(BASE);
        await page.fill('input[name=name]', names[who]);
        await page.fill('input[name=password]', 'correct-horse');
        await page.click('button[type=submit]');
        // The second player can be paired at once and skip the matchmaking screen.
        await page.waitForURL(/\/(play|match\/\d+)$/);
    }
});

let matchId;
await step('matchmaking pairs them into the same match', async () => {
    await Promise.all([a.waitForURL(/\/match\/\d+/, { timeout: 15000 }), b.waitForURL(/\/match\/\d+/, { timeout: 15000 })]);
    matchId = a.url().match(/match\/(\d+)/)[1];
    expect(b.url().endsWith(`/match/${matchId}`), `B is in ${b.url()}`);
    return `match #${matchId}`;
});

await step('the countdown runs, then both games start', async () => {
    await a.waitForSelector('[data-testid=countdown]', { timeout: 5000 });
    // Hand both sides to the bot autopilot (a reload also proves a match can be resumed).
    await Promise.all([a.goto(`${BASE}/match/${matchId}?autopilot=1`), b.goto(`${BASE}/match/${matchId}?autopilot=1`)]);
    await Promise.all([waitForStatus(a, 'playing'), waitForStatus(b, 'playing')]);
});

let midMatch;
await step(`both autopilots play for ${BOT_PHASE_MS / 1000}s with every lock validated by the server`, async () => {
    await a.waitForTimeout(BOT_PHASE_MS / 2);
    await a.screenshot({ path: `${OUT}/01-match-player-a.png` });
    await b.screenshot({ path: `${OUT}/02-match-player-b.png` });
    await a.waitForTimeout(BOT_PHASE_MS / 2);
    midMatch = await apiGet(a, `/api/matches/${matchId}`);
    const [p1, p2] = midMatch.match.players;
    expect(midMatch.match.status === 'active', `match is ${midMatch.match.status}`);
    expect(p1.pieces > 15 && p2.pieces > 15, `pieces ${p1.pieces}/${p2.pieces}`);
    const resyncs = await Promise.all([a, b].map((p) => p.locator('text=/resync/').count()));
    expect(resyncs.every((n) => n === 0), 'a client needed a server resync');
    return `${p1.user.name}: ${p1.pieces} pieces, ${p1.lines} lines, sent ${p1.garbage_sent}; ${p2.user.name}: ${p2.pieces} pieces, ${p2.lines} lines, sent ${p2.garbage_sent}`;
});

await step("each side sees the opponent's score arrive over WebSockets", async () => {
    const opponentScoreOnA = await a.locator('[data-testid=hud-score]').nth(1).innerText();
    const ownScoreOnB = await b.locator('[data-testid=hud-score]').nth(0).innerText();
    const serverB = midMatch.match.players.find((p) => p.user.name === names.B).score;
    expect(Number(opponentScoreOnA.replace(/\D/g, '')) >= serverB, `A shows ${opponentScoreOnA} for B, server had ${serverB}`);
    return `A sees B at ${opponentScoreOnA}; B sees itself at ${ownScoreOnB}`;
});

await step('B reloads without autopilot and resumes from the server state', async () => {
    await b.goto(`${BASE}/match/${matchId}`);
    await waitForStatus(b, 'playing');
    const server = (await apiGet(b, `/api/matches/${matchId}`)).you.state;
    const shown = Number((await b.locator('[data-testid=hud-score]').nth(0).innerText()).replace(/\D/g, ''));
    expect(shown === server.score, `HUD ${shown} vs server ${server.score}`);
    return `resumed at piece ${server.pieces}`;
});

await step('B hard-drops by keyboard until topping out, ending the match', async () => {
    for (let i = 0; i < 200 && (await status(b)) !== 'finished'; i++) {
        await b.keyboard.press('Space');
        await b.waitForTimeout(120);
    }
    await Promise.all([waitForStatus(a, 'finished'), waitForStatus(b, 'finished')]);
});

await step('both players see the result and a symmetric Elo change', async () => {
    const headlineA = await a.innerText('[data-testid=result-headline]');
    const headlineB = await b.innerText('[data-testid=result-headline]');
    expect(headlineA === 'Victory' && headlineB === 'Defeat', `A: ${headlineA}, B: ${headlineB}`);
    const deltas = await a.locator('[data-testid=rating-delta]').allInnerTexts();
    expect(deltas.includes('(+16)') && deltas.includes('(-16)'), `deltas ${deltas}`);
    await a.screenshot({ path: `${OUT}/03-result-winner.png` });
    await b.screenshot({ path: `${OUT}/04-result-loser.png` });
});

await step('the replay records garbage that crossed between the boards', async () => {
    const replay = await apiGet(a, `/api/matches/${matchId}/replay`);
    const moves = Object.values(replay.moves).flat();
    const withGarbage = moves.filter((m) => m.garbage);
    const sent = replay.match.players.reduce((n, p) => n + p.garbage_sent, 0);
    const received = replay.match.players.reduce((n, p) => n + p.garbage_received, 0);
    return `${moves.length} moves stored, ${sent} garbage lines sent, ${received} received, ${withGarbage.length} locks raised garbage`;
});

await step('profile and leaderboard reflect the result', async () => {
    await a.goto(`${BASE}/players/${names.A}`);
    await a.waitForSelector('[data-testid=profile]');
    expect((await a.innerText('[data-testid=stat-rating]')) === '1016', 'winner rating');
    expect((await a.innerText('[data-testid=stat-wins]')) === '1', 'winner wins');
    await a.goto(`${BASE}/leaderboard`);
    await a.waitForSelector(`[data-testid=leaderboard] >> text=${names.A}`);
    await a.screenshot({ path: `${OUT}/05-leaderboard.png` });
});

await step('the replay viewer rebuilds and plays the match', async () => {
    await a.goto(`${BASE}/replay/${matchId}`);
    await a.waitForSelector('[data-testid=replay-view] canvas');
    await a.selectOption('[data-testid=replay-view] select', '4');
    await a.waitForTimeout(4000);
    await a.screenshot({ path: `${OUT}/06-replay.png` });
});

await step('a lone player is paired with a house bot that plays from the server', async () => {
    await a.goto(`${BASE}/play`);
    await a.waitForURL(/\/match\/\d+/, { timeout: 25000 });
    const botMatchId = a.url().match(/match\/(\d+)/)[1];
    await waitForStatus(a, 'playing', 10000);
    await a.waitForTimeout(6000);
    const { match } = await apiGet(a, `/api/matches/${botMatchId}`);
    const bot = match.players.find((p) => p.user.is_bot);
    expect(bot, 'no bot in the match');
    expect(bot.pieces >= 4, `the bot locked ${bot.pieces} pieces (is bots:play running?)`);
    expect((await a.locator('text=BOT').count()) > 0, 'no BOT badge on the HUD');
    await a.screenshot({ path: `${OUT}/09-bot-match.png` });
    await a.click('[data-testid=forfeit]');
    await a.click('[data-testid=forfeit-confirm]');
    await waitForStatus(a, 'finished');
    return `${bot.user.name} locked ${bot.pieces} pieces in about 6s`;
});

await step('practice mode runs a local game against the bot', async () => {
    await b.goto(`${BASE}/practice`);
    await b.click('[data-testid=practice-start]');
    await b.waitForSelector('[data-testid=practice-view][data-status=playing]', { timeout: 6000 });
    for (let i = 0; i < 6; i++) {
        await b.keyboard.press(i % 2 ? 'ArrowLeft' : 'ArrowRight');
        await b.keyboard.press('Space');
    }
    await b.waitForTimeout(2500);
    await b.screenshot({ path: `${OUT}/07-practice.png` });
});

await step('phone-width layout fits without horizontal scrolling', async () => {
    await b.setViewportSize({ width: 390, height: 844 });
    await b.goto(`${BASE}/practice`);
    await b.waitForSelector('[data-testid=game-canvas]');
    const overflow = await b.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    await b.screenshot({ path: `${OUT}/08-phone.png`, fullPage: true });
    expect(overflow <= 0, `page scrolls horizontally by ${overflow}px`);
});

await browser.close();
console.log(results.join('\n'));
process.exit(failed ? 1 : 0);
