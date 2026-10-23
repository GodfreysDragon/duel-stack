<?php

namespace Tests\Unit;

use App\Game\Pieces;
use App\Game\PlayerState;
use App\Game\Randomizer;
use App\Game\Reachability;
use App\Game\Simulation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server only accepts a lock if its PHP engine agrees with the client's JS
 * engine, so the two must match exactly. tests/Fixtures/engine-parity.json is
 * produced by the JS engine (node tests/js/parity-fixture.mjs) and replayed here.
 */
class EngineParityTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/engine-parity.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function sequences(): iterable
    {
        foreach (self::fixture()['sequences'] as $sequence) {
            yield "seed {$sequence['seed']}" => [$sequence['seed'], $sequence['pieces']];
        }
    }

    /**
     * @return iterable<string, array{int, list<array<string, mixed>>}>
     */
    public static function games(): iterable
    {
        foreach (self::fixture()['games'] as $game) {
            yield "game seed {$game['seed']}" => [$game['seed'], $game['locks']];
        }
    }

    #[DataProvider('sequences')]
    public function test_piece_sequence_matches_the_js_randomizer(int $seed, string $expected): void
    {
        $randomizer = new Randomizer($seed);

        $actual = implode('', array_map($randomizer->piece(...), range(0, strlen($expected) - 1)));

        $this->assertSame($expected, $actual);
    }

    /**
     * @param  list<array{lock: array<string, mixed>, expect: array<string, mixed>}>  $locks
     */
    #[DataProvider('games')]
    public function test_every_lock_of_a_js_game_produces_the_same_state(int $seed, array $locks): void
    {
        $randomizer = new Randomizer($seed);
        $state = PlayerState::initial($randomizer);

        foreach ($locks as $number => ['lock' => $lock, 'expect' => $expect]) {
            $this->assertTrue(
                Reachability::canReach($state->board, $lock['piece'], $lock['rotation'], $lock['x'], $lock['y']),
                "Lock #{$number} would be rejected as unreachable.",
            );
            $result = Simulation::applyLock($state, $randomizer, $lock);
            $state = $result['state'];

            $this->assertSame($expect, [
                'board' => $state->board->toString(),
                'current' => $state->current,
                'nextIndex' => $state->nextIndex,
                'hold' => $state->hold,
                'combo' => $state->combo,
                'b2b' => $state->b2b,
                'score' => $state->score,
                'lines' => $state->lines,
                'pieces' => $state->pieces,
                'toppedOut' => $state->toppedOut,
                'cleared' => $result['cleared'],
                'attack' => $result['attack'],
            ], "Lock #{$number} diverged.");
        }
    }

    public function test_the_srs_kick_tables_match_the_js_engine(): void
    {
        foreach (self::fixture()['kicks'] as $turn => $kicks) {
            [$type, $from, $to] = [$turn[0], (int) $turn[1], (int) $turn[3]];

            $this->assertSame($kicks, Pieces::kicks($type, $from, $to), "Kicks for {$turn} differ.");
        }
    }
}
