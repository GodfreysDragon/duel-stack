# Stack Duel

A real-time 1v1 block-stacking battle (a Tetris-style race) in the browser. Players sign in and get matched by rating.
Both players are dealt the same seeded pieces. Clearing lines sends garbage rows to the opponent, and the first player
to top out loses. Elo ratings, lifetime stats, a leaderboard and full match replays are kept in the database.

**Stack:** Laravel 13 · PHP 8.4 · Laravel Reverb (WebSockets) + Echo · Vue 3 + Vue Router · PixiJS 8 · Tailwind 4 ·
SQLite in development, with migrations written for MySQL.

See [Project_Summary.md](Project_Summary.md) for what was built, the verification status, known issues and next steps.

## How it works

- **The engine exists twice and the two copies must agree.** The JS copy is in `resources/js/engine`; the PHP copy is
  in `app/Game`.
  - The client runs the real-time part: gravity, DAS, SRS rotation kicks and lock delay.
  - After every lock, the client POSTs where the piece came to rest. The server replays that lock through its own copy
    of the engine. It checks that the piece is the right one for the seeded sequence and the hold, that it fits, and
    that it is resting on something. The server then computes the line clears, score and attack itself.
  - A fixture generated from the JS engine (921 locks of bot games) is replayed through the PHP engine in the tests,
    so the two copies can't drift apart.
- **Garbage.** An attack creates a garbage batch for the opponent and is broadcast over Reverb. The opponent's client
  raises the batch on its next lock that clears no lines, and says which batch ids it applied. The server checks that
  they are the oldest pending batches, in order, and rejects any lock that skips a batch older than the 3-second grace
  period.
- **Resync.** A rejected lock returns 409 with the server's state, and the client resyncs to it.
- **Matchmaking.** Pairing runs under an atomic cache lock. It picks the closest-rated player within a rating window
  that grows the longer someone waits (±150, plus 25 per second, capped at ±1000).
- **Match end.** A top-out, a forfeit, or an opponent who has been silent for more than 20 seconds (the winner claims
  it) ends the match. The server then updates Elo (K=32) and lifetime stats in the same transaction.
- **Replays.** Every accepted lock is stored with the garbage that rose with it. The replay viewer rebuilds both boards
  with the same engine, so a replay is exact.
- **Practice.** Practice mode is a local match against a heuristic bot (easy, medium or hard) and never touches the
  server. The same bot can drive one side of a real match (`?autopilot=1`); the end-to-end test uses this.

## Running it locally

```sh
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed        # seeds alice / bob (password: "password") and a populated leaderboard
npm run build

php artisan reverb:start          # WebSockets on :8085
php -d opcache.enable_cli=1 -S 127.0.0.1:8040 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
php artisan schedule:work         # optional: expires matches abandoned by both players
```

Open http://127.0.0.1:8040 in two browsers (or one normal window and one private window), sign in as two players and
press **Play ranked** in both.

Two Windows-specific points:
- `php artisan serve --port=8040` also works, but the PHP built-in server runs without opcache, so each request takes
  about 500ms on this machine. With `-d opcache.enable_cli=1` it takes about 10ms.
- Use `REVERB_HOST=127.0.0.1`, not `localhost`. On Windows, `localhost` tries IPv6 first, which adds about 220ms to
  every broadcast.

To use MySQL instead of SQLite, set `DB_CONNECTION=mysql` and the `DB_*` values in `.env`. Nothing else changes.

## Tests

```sh
php artisan test                 # 68 PHPUnit tests: engine parity, rules, matchmaking, locks, garbage, Elo, channels, ...
npm run test:js                  # 13 node:test tests for the JS engine, the Game controller and the bot
npm run fixture                  # regenerate tests/Fixtures/engine-parity.json after changing the engine
E2E_URL=http://127.0.0.1:8040 npm run test:e2e   # two headless Edge browsers play a full ranked match
```

## Controls

← → move · ↓ soft drop · Space hard drop · ↑/X rotate right · Z/Ctrl rotate left · A rotate 180° · C/Shift hold.
On touch devices, on-screen buttons appear under the board.
