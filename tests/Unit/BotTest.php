<?php

namespace Tests\Unit;

use App\Game\Board;
use App\Game\Bot;
use App\Game\PlayerState;
use App\Game\Randomizer;
use App\Game\Simulation;
use PHPUnit\Framework\TestCase;

class BotTest extends TestCase
{
    public function test_it_drops_an_i_piece_into_a_well_for_a_tetris(): void
    {
        $well = str_repeat('GGGGGGGGG.', 4);
        $board = Board::fromString(str_repeat('.', Board::WIDTH * (Board::HEIGHT - 4)).$well);
        // Seed 0 deals I, O, T, L, ... (see engine-parity.json).
        $state = new PlayerState($board, 'I', 1, null, -1, false, 0, 0, 0, false);

        $move = Bot::chooseMove($state, new Randomizer(0));

        $this->assertSame(['piece' => 'I', 'rotation' => 1, 'x' => 7, 'y' => 18, 'held' => false], $move);
        $result = Simulation::applyLock($state, new Randomizer(0), [...$move, 'garbage' => []]);
        $this->assertCount(4, $result['cleared']);
        $this->assertTrue($result['state']->board->isEmpty());
    }

    public function test_it_considers_the_hold_piece(): void
    {
        // Only an O fits the 2x2 gap without leaving holes; the current piece is an S.
        $board = Board::fromString(str_repeat('.', Board::WIDTH * (Board::HEIGHT - 2)).'GGGG..GGGGGGGG..GGGG');
        $state = new PlayerState($board, 'S', 1, 'O', -1, false, 0, 0, 0, false);

        $move = Bot::chooseMove($state, new Randomizer(0));

        $this->assertSame('O', $move['piece']);
        $this->assertTrue($move['held']);
    }

    public function test_every_move_is_legal_and_the_hard_bot_survives_a_long_game(): void
    {
        $randomizer = new Randomizer(12345);
        $state = PlayerState::initial($randomizer);

        for ($piece = 0; $piece < 300; $piece++) {
            $move = Bot::chooseMove($state, $randomizer);
            $this->assertNotNull($move);

            $state = Simulation::applyLock($state, $randomizer, [...$move, 'garbage' => []])['state'];
            $this->assertFalse($state->toppedOut, "Topped out after {$piece} pieces.");
        }

        $this->assertGreaterThan(100, $state->lines);
    }

    public function test_weaker_bots_sometimes_take_a_lesser_move(): void
    {
        $randomizer = new Randomizer(0);
        $state = PlayerState::initial($randomizer);

        $best = Bot::chooseMove($state, $randomizer, mistakeRate: 0.25, random: fn () => 0.5);
        $mistake = Bot::chooseMove($state, $randomizer, mistakeRate: 0.25, random: fn () => 0.1);

        $this->assertNotEquals($best, $mistake);
    }
}
