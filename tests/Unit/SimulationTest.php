<?php

namespace Tests\Unit;

use App\Game\Board;
use App\Game\InvalidLock;
use App\Game\PlayerState;
use App\Game\Randomizer;
use App\Game\Rules;
use App\Game\Simulation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SimulationTest extends TestCase
{
    /** Seed 0 deals I, O, T, L, ... (see engine-parity.json). */
    private const SEED = 0;

    private Randomizer $randomizer;

    protected function setUp(): void
    {
        $this->randomizer = new Randomizer(self::SEED);
    }

    /**
     * A board whose bottom rows are $rows (top row first); everything above is empty.
     *
     * @param  list<string>  $rows
     */
    private function board(array $rows): Board
    {
        return Board::fromString(str_repeat('.', Board::WIDTH * (Board::HEIGHT - count($rows))).implode('', $rows));
    }

    private function state(Board $board, string $current, ?string $hold = null, int $combo = -1, bool $b2b = false): PlayerState
    {
        return new PlayerState($board, $current, 1, $hold, $combo, $b2b, 0, 0, 0, false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{piece: string, rotation: int, x: int, y: int, held: bool, garbage: list<array{lines: int, hole: int}>}
     */
    private function lock(string $piece, int $rotation, int $x, int $y, array $overrides = []): array
    {
        return [...['piece' => $piece, 'rotation' => $rotation, 'x' => $x, 'y' => $y, 'held' => false, 'garbage' => []], ...$overrides];
    }

    public function test_a_hard_dropped_piece_is_accepted_and_the_next_piece_is_dealt(): void
    {
        $state = PlayerState::initial($this->randomizer);

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('I', 0, 3, 20));

        $this->assertSame(str_repeat('.', 210).'...IIII...', $result['state']->board->toString());
        $this->assertSame($this->randomizer->piece(1), $result['state']->current);
        $this->assertSame(2, $result['state']->nextIndex);
        $this->assertSame(1, $result['state']->pieces);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function illegalLocks(): iterable
    {
        yield 'wrong piece' => [['piece' => 'T', 'rotation' => 0, 'x' => 3, 'y' => 20], 'Expected piece I, got T.'];
        yield 'floating' => [['piece' => 'I', 'rotation' => 0, 'x' => 3, 'y' => 10], 'Piece is floating.'];
        yield 'outside the wall' => [['piece' => 'I', 'rotation' => 0, 'x' => 7, 'y' => 20], 'Piece overlaps the stack or the walls.'];
        yield 'below the floor' => [['piece' => 'I', 'rotation' => 0, 'x' => 3, 'y' => 21], 'Piece overlaps the stack or the walls.'];
        yield 'bad rotation' => [['piece' => 'I', 'rotation' => 4, 'x' => 3, 'y' => 20], 'Invalid rotation.'];
    }

    /**
     * @param  array<string, mixed>  $lock
     */
    #[DataProvider('illegalLocks')]
    public function test_illegal_locks_are_rejected(array $lock, string $message): void
    {
        $this->expectException(InvalidLock::class);
        $this->expectExceptionMessage($message);

        Simulation::applyLock(PlayerState::initial($this->randomizer), $this->randomizer, [...$lock, 'held' => false, 'garbage' => []]);
    }

    public function test_a_piece_cannot_overlap_the_stack(): void
    {
        $state = $this->state($this->board(['IIII......']), 'I');

        $this->expectExceptionMessage('Piece overlaps the stack or the walls.');

        Simulation::applyLock($state, $this->randomizer, $this->lock('I', 0, 0, 20));
    }

    public function test_holding_with_an_empty_hold_slot_plays_the_next_piece(): void
    {
        $state = PlayerState::initial($this->randomizer); // current I, next O

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('O', 0, 3, 20, ['held' => true]));

        $this->assertSame('I', $result['state']->hold);
        $this->assertSame($this->randomizer->piece(2), $result['state']->current);
        $this->assertSame(3, $result['state']->nextIndex);
    }

    public function test_holding_with_a_full_hold_slot_swaps_pieces(): void
    {
        $state = $this->state(Board::empty(), 'T', hold: 'O');

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('O', 0, 3, 20, ['held' => true]));

        $this->assertSame('T', $result['state']->hold);
        $this->assertSame(2, $result['state']->nextIndex);
    }

    public function test_holding_but_placing_the_original_piece_is_rejected(): void
    {
        $this->expectExceptionMessage('Expected piece O, got I.');

        Simulation::applyLock(PlayerState::initial($this->randomizer), $this->randomizer, $this->lock('I', 0, 3, 20, ['held' => true]));
    }

    public function test_a_tetris_clears_four_rows_and_back_to_back_tetrises_earn_a_bonus(): void
    {
        $well = array_fill(0, 4, 'GGGGGGGGG.');
        $state = $this->state($this->board(['G.........', ...$well]), 'I', b2b: true);

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('I', 1, 7, 18));

        $this->assertSame([18, 19, 20, 21], $result['cleared']);
        $this->assertSame(Rules::LINE_ATTACK[4] + Rules::B2B_ATTACK_BONUS, $result['attack']);
        $this->assertSame(800 + 400, $result['points']);
        $this->assertTrue($result['state']->b2b);
        $this->assertSame(str_repeat('.', 210).'G.........', $result['state']->board->toString());
    }

    public function test_combos_add_attack_and_points_and_reset_on_a_non_clearing_lock(): void
    {
        $state = $this->state($this->board(['G.........', 'GGGGGG....']), 'I', combo: 3);

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('I', 0, 6, 20));

        $this->assertSame(4, $result['state']->combo);
        $this->assertSame(Rules::COMBO_ATTACK[4], $result['attack']);
        $this->assertSame(100 + 4 * Rules::COMBO_POINTS, $result['points']);

        $next = Simulation::applyLock($result['state'], $this->randomizer, $this->lock('O', 0, 3, 20));
        $this->assertSame(-1, $next['state']->combo);
    }

    public function test_a_perfect_clear_adds_its_bonus(): void
    {
        $state = $this->state($this->board(['GGGGGG....']), 'I');

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('I', 0, 6, 20));

        $this->assertTrue($result['state']->board->isEmpty());
        $this->assertSame(Rules::PERFECT_CLEAR_ATTACK, $result['attack']);
        $this->assertSame(100 + Rules::PERFECT_CLEAR_POINTS, $result['points']);
    }

    public function test_garbage_rises_from_the_bottom_with_one_hole(): void
    {
        $state = PlayerState::initial($this->randomizer);

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('I', 0, 3, 20, ['garbage' => [['lines' => 2, 'hole' => 4]]]));

        $this->assertSame(
            str_repeat('.', 190).'...IIII...'.'GGGG.GGGGG'.'GGGG.GGGGG',
            $result['state']->board->toString(),
        );
        $this->assertFalse($result['state']->toppedOut);
    }

    public function test_garbage_cannot_rise_on_a_lock_that_clears_lines(): void
    {
        $state = $this->state($this->board(['GGGGGG....']), 'I');

        $this->expectExceptionMessage('Garbage can only rise on a lock that clears no lines.');

        Simulation::applyLock($state, $this->randomizer, $this->lock('I', 0, 6, 20, ['garbage' => [['lines' => 1, 'hole' => 0]]]));
    }

    public function test_garbage_that_pushes_blocks_off_the_top_tops_the_player_out(): void
    {
        // An I standing in column 0 reaches the top row; one garbage row pushes it off.
        $state = $this->state($this->board(array_fill(0, 18, 'GGGGGGGG..')), 'I');

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('I', 1, -2, 0, ['garbage' => [['lines' => 1, 'hole' => 9]]]));

        $this->assertTrue($result['state']->toppedOut);
    }

    public function test_a_stack_that_blocks_the_spawn_tops_the_player_out(): void
    {
        // An I standing in column 4 occupies the spawn area of every piece.
        $state = $this->state($this->board(array_fill(0, 18, 'GGGGGGGGG.')), 'I');

        $result = Simulation::applyLock($state, $this->randomizer, $this->lock('I', 1, 2, 0));

        $this->assertTrue($result['state']->toppedOut);
    }

    public function test_a_topped_out_player_cannot_lock_again(): void
    {
        $state = new PlayerState(Board::empty(), 'I', 1, null, -1, false, 0, 0, 0, true);

        $this->expectExceptionMessage('Player has already topped out.');

        Simulation::applyLock($state, $this->randomizer, $this->lock('I', 0, 3, 20));
    }
}
