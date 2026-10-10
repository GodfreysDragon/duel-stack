<?php

namespace App\Game;

use InvalidArgumentException;

/**
 * Immutable 10x22 playfield (rows 0-1 are the hidden spawn area), mirroring
 * resources/js/engine/board.js. Stored as a 220-char string: '.' is empty,
 * a piece letter is a locked block and 'G' is garbage.
 */
final class Board
{
    public const WIDTH = 10;

    public const HEIGHT = 22;

    public const HIDDEN_ROWS = 2;

    private const EMPTY = '.';

    /**
     * @param  list<string>  $rows
     */
    private function __construct(private readonly array $rows) {}

    public static function empty(): self
    {
        return new self(array_fill(0, self::HEIGHT, str_repeat(self::EMPTY, self::WIDTH)));
    }

    public static function fromString(string $cells): self
    {
        if (strlen($cells) !== self::WIDTH * self::HEIGHT) {
            throw new InvalidArgumentException('A board needs exactly '.(self::WIDTH * self::HEIGHT).' cells.');
        }

        return new self(str_split($cells, self::WIDTH));
    }

    public function toString(): string
    {
        return implode('', $this->rows);
    }

    public function fits(string $type, int $rotation, int $x, int $y): bool
    {
        foreach (Pieces::cells($type, $rotation) as [$cx, $cy]) {
            $cx += $x;
            $cy += $y;
            if ($cx < 0 || $cx >= self::WIDTH || $cy < 0 || $cy >= self::HEIGHT || $this->rows[$cy][$cx] !== self::EMPTY) {
                return false;
            }
        }

        return true;
    }

    public function isGrounded(string $type, int $rotation, int $x, int $y): bool
    {
        return ! $this->fits($type, $rotation, $x, $y + 1);
    }

    public function spawnFits(string $type): bool
    {
        return $this->fits($type, 0, Pieces::SPAWN_X, Pieces::SPAWN_Y);
    }

    public function place(string $type, int $rotation, int $x, int $y): self
    {
        $rows = $this->rows;
        foreach (Pieces::cells($type, $rotation) as [$cx, $cy]) {
            $rows[$y + $cy][$x + $cx] = $type;
        }

        return new self($rows);
    }

    /**
     * Removes full rows.
     *
     * @return array{0: self, 1: list<int>} the new board and the cleared row indexes
     */
    public function clearLines(): array
    {
        $kept = [];
        $cleared = [];
        foreach ($this->rows as $y => $row) {
            if (str_contains($row, self::EMPTY)) {
                $kept[] = $row;
            } else {
                $cleared[] = $y;
            }
        }
        $fresh = array_fill(0, count($cleared), str_repeat(self::EMPTY, self::WIDTH));

        return [new self([...$fresh, ...$kept]), $cleared];
    }

    /**
     * Pushes garbage rows in from the bottom.
     *
     * @return array{0: self, 1: bool} the new board and whether blocks were pushed off the top
     */
    public function addGarbage(int $lines, int $hole): array
    {
        $emptyRow = str_repeat(self::EMPTY, self::WIDTH);
        $overflow = false;
        foreach (array_slice($this->rows, 0, $lines) as $row) {
            $overflow = $overflow || $row !== $emptyRow;
        }
        $garbageRow = str_repeat('G', $hole).self::EMPTY.str_repeat('G', self::WIDTH - $hole - 1);

        return [new self([...array_slice($this->rows, $lines), ...array_fill(0, $lines, $garbageRow)]), $overflow];
    }

    /**
     * The row a piece would come to rest on if dropped straight down from ($x, $y).
     */
    public function dropY(string $type, int $rotation, int $x, int $y): int
    {
        while ($this->fits($type, $rotation, $x, $y + 1)) {
            $y++;
        }

        return $y;
    }

    /**
     * Height of each column's highest block (0 for an empty column).
     *
     * @return list<int>
     */
    public function columnHeights(): array
    {
        $heights = [];
        for ($x = 0; $x < self::WIDTH; $x++) {
            $y = 0;
            while ($y < self::HEIGHT && $this->rows[$y][$x] === self::EMPTY) {
                $y++;
            }
            $heights[] = self::HEIGHT - $y;
        }

        return $heights;
    }

    /**
     * Empty cells with a block somewhere above them in the same column.
     */
    public function holes(): int
    {
        $holes = 0;
        foreach ($this->columnHeights() as $x => $height) {
            for ($y = self::HEIGHT - $height + 1; $y < self::HEIGHT; $y++) {
                if ($this->rows[$y][$x] === self::EMPTY) {
                    $holes++;
                }
            }
        }

        return $holes;
    }

    public function isEmpty(): bool
    {
        return trim($this->toString(), self::EMPTY) === '';
    }

    /**
     * Whether a piece locked entirely inside the hidden rows ("lock out").
     */
    public static function lockedOut(string $type, int $rotation, int $y): bool
    {
        foreach (Pieces::cells($type, $rotation) as [, $cy]) {
            if ($y + $cy >= self::HIDDEN_ROWS) {
                return false;
            }
        }

        return true;
    }
}
