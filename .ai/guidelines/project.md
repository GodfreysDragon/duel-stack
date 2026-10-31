# Duel Stack: notes for contributors and coding agents

A real-time 1v1 block-stacking battle: Laravel 13 (PHP 8.3+), Laravel Reverb and Echo, Vue 3 with Vue Router, PixiJS 8
and Tailwind 4, on SQLite or MySQL 8. `README.md` explains the design and its security model. Read it before changing
the engine, lock validation, garbage, matchmaking or ratings.

## Commands

```sh
composer run dev                 # app server (port 8040), queue, Vite, Reverb, bots:play and the scheduler
php artisan test                 # PHPUnit: engine parity, rules, bot, matchmaking, locks, garbage, Elo, channels
npm run test:js                  # node:test for the JS engine, lockSync and the bot
npm run fixture                  # regenerate tests/Fixtures/engine-parity.json after changing the JS engine
vendor/bin/pint --test           # code style, as CI runs it
vendor/bin/phpstan analyse       # Larastan, level 8 (phpstan.neon)
```

## Invariants to keep

- **The engine exists twice and must agree.** `resources/js/engine` plays the game; `app/Game` (`Simulation`,
  `Board`, `Randomizer`, `Rules`, `Pieces`, `Bot`) mirrors it. Change both sides together, then `npm run fixture`:
  `engine.test.mjs` fails if the committed fixture is stale, and `EngineParityTest` replays it in PHP. `Randomizer` is
  a 32-bit LCG so PHP ints and JS doubles give the same sequence; keep it that way.
- **The server is authoritative.** The client sends only where a piece came to rest (`SubmitLockRequest` checks shape
  only). Line clears, score, attack, garbage, the result and Elo are computed server-side. Never trust a client value
  for any of them.
- **Every lock goes through `LockProcessor::submit`**, players and bots alike (`BotDriver`). It runs in a transaction
  that `lockForUpdate()`s the match, then the player and opponent rows, which serialises both players' locks and the
  garbage between them. Keep its checks: piece number, countdown, pace (`Rules::MAX_PIECES_PER_SECOND` over the whole
  match), `Simulation::applyLock`, `Reachability::canReach`, oldest-first garbage and the `GARBAGE_GRACE_MS` rule.
  Reject with `LockRejected` (passing the player): it renders a 409 with the state `LockSync` resyncs to.
- **Ending a match goes through `MatchFinisher::finish`, with the match row locked**, after re-checking
  `isFinished()` under the lock (see `MatchController::forfeit`/`claim`, `ExpireIdleMatches`, `BotDriver`).
- **Rating changes are relative** (`rating = rating + delta` in `MatchFinisher::record`): a bot plays many matches at
  once, so never write back an absolute rating computed from `rating_before`. Elo math lives in `Elo`.
- **Broadcast only after commit.** `BoardUpdated` and `GarbageSent` are sent after `LockProcessor`'s transaction,
  `MatchEnded` via `DB::afterCommit`. Events implement `ShouldBroadcastNow` and `ShouldRescue`, so a Reverb outage
  never fails a committed write and no queue worker is needed in production.
- **Two match channels.** `match.{id}` is the players' presence channel (authorised in `routes/channels.php`);
  `match.{id}.watch` is public for spectators. Garbage and resume state stay off the public channel and out of
  `MatchController::watch`. Authorise endpoints with `GameMatchPolicy` (`view`, `play`).
- **Matchmaking runs under `Cache::lock('matchmaking')`** in `Matchmaker::join`; keep pairing inside it.
- **The bot daemon is stateless.** `bots:play` is one `Isolatable` process; `Bot::thinkMilliseconds` is a hash of seed
  and piece number, so a restart resumes at the same pace. Don't keep per-match state in the daemon.
- **Garbage timestamps keep milliseconds** (`dateTime('created_at', 3)` and the `DateFormat` on `GarbageBatch`); the
  grace-period check in `LockProcessor` compares against them. Oldest-first means by `id` (`incomingGarbage()`).

## Conventions

- CI runs PHPUnit on SQLite and MySQL 8.4, plus Pint and Larastan. Keep raw SQL portable across both.
- Simulate time with `$this->travel()` and fake the events (see `LockSubmissionTest`). Create matches with
  `GameMatch::factory()->seeded($seed)->between($a, $b)`; seed 0 deals I, O, T, L.
- The e2e autopilot (`?autopilot=1`) must stay compiled out of production builds (`MatchView.vue`).
