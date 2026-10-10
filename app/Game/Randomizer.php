<?php

namespace App\Game;

/**
 * Seeded 7-bag piece sequence, identical to resources/js/engine/randomizer.js.
 *
 * A 32-bit LCG keeps every intermediate value below 2^53, so PHP ints and JS
 * doubles produce the same sequence for the same seed.
 */
final class Randomizer
{
    private const MOD = 4294967296;

    private int $state;

    /** @var list<string> */
    private array $pieces = [];

    public function __construct(int $seed)
    {
        $this->state = (($seed % self::MOD) + self::MOD) % self::MOD;
    }

    public function piece(int $index): string
    {
        while (count($this->pieces) <= $index) {
            $this->refill();
        }

        return $this->pieces[$index];
    }

    private function next(): int
    {
        $this->state = ($this->state * 1664525 + 1013904223) % self::MOD;

        return $this->state;
    }

    private function refill(): void
    {
        $bag = Pieces::TYPES;
        for ($i = count($bag) - 1; $i > 0; $i--) {
            $j = intdiv($this->next() * ($i + 1), self::MOD);
            [$bag[$i], $bag[$j]] = [$bag[$j], $bag[$i]];
        }
        array_push($this->pieces, ...$bag);
    }
}
