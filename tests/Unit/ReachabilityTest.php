<?php

namespace Tests\Unit;

use App\Game\Board;
use App\Game\Reachability;
use PHPUnit\Framework\TestCase;

class ReachabilityTest extends TestCase
{
    /**
     * A board whose bottom rows are $rows (top row first); everything above is empty.
     *
     * @param  list<string>  $rows
     */
    private function board(array $rows): Board
    {
        return Board::fromString(str_repeat('.', Board::WIDTH * (Board::HEIGHT - count($rows))).implode('', $rows));
    }

    public function test_a_piece_can_be_tucked_under_a_ledge(): void
    {
        $board = $this->board(['GGGGGG....', '..........', 'GGGGGGGGG.']);

        // Dropped straight down at x = 0 the I stops on the ledge; sliding it in from the right gets it underneath.
        $this->assertSame(17, $board->dropY('I', 0, 0, 0));
        $this->assertTrue(Reachability::canReach($board, 'I', 0, 0, 19));
    }

    public function test_a_t_spin_slot_is_reached_through_a_rotation_kick(): void
    {
        $board = $this->board(['GG........', 'G...GGGGGG', 'GG.GGGGGGG']);

        $this->assertLessThan(19, $board->dropY('T', 2, 1, 0), 'the overhang blocks a straight drop');
        $this->assertTrue(Reachability::canReach($board, 'T', 2, 1, 19));
    }

    public function test_a_sealed_cavity_cannot_be_reached(): void
    {
        $board = $this->board(['GGGGGGGGGG', '....GGGGGG', 'GGGGGGGGGG']);

        $this->assertTrue($board->fits('I', 0, 0, 19) && $board->isGrounded('I', 0, 0, 19));
        $this->assertFalse(Reachability::canReach($board, 'I', 0, 0, 19));
    }

    public function test_nothing_is_reachable_when_the_spawn_point_is_blocked(): void
    {
        $board = Board::fromString(str_repeat('G', Board::WIDTH).str_repeat('.', Board::WIDTH * (Board::HEIGHT - 1)));

        $this->assertSame([], Reachability::positions($board, 'T'));
    }
}
