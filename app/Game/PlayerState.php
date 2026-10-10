<?php

namespace App\Game;

/**
 * One player's state between locks, mirroring the object built by initialState() in simulation.js.
 */
final readonly class PlayerState
{
    public function __construct(
        public Board $board,
        public string $current,
        public int $nextIndex,
        public ?string $hold,
        public int $combo,
        public bool $b2b,
        public int $score,
        public int $lines,
        public int $pieces,
        public bool $toppedOut,
    ) {}

    public static function initial(Randomizer $randomizer): self
    {
        return new self(Board::empty(), $randomizer->piece(0), 1, null, -1, false, 0, 0, 0, false);
    }
}
