<?php

namespace App\Game;

/**
 * Piece shapes and spawn point, mirroring resources/js/engine/constants.js.
 */
final class Pieces
{
    public const TYPES = ['I', 'J', 'L', 'O', 'S', 'T', 'Z'];

    public const SPAWN_X = 3;

    public const SPAWN_Y = 0;

    /**
     * Rotation-state-0 cells as [x, y] inside each piece's bounding box (y grows downward).
     *
     * @var array<string, array{size: int, cells: list<array{int, int}>}>
     */
    private const BASE = [
        'I' => ['size' => 4, 'cells' => [[0, 1], [1, 1], [2, 1], [3, 1]]],
        'J' => ['size' => 3, 'cells' => [[0, 0], [0, 1], [1, 1], [2, 1]]],
        'L' => ['size' => 3, 'cells' => [[2, 0], [0, 1], [1, 1], [2, 1]]],
        'O' => ['size' => 4, 'cells' => [[1, 0], [2, 0], [1, 1], [2, 1]]],
        'S' => ['size' => 3, 'cells' => [[1, 0], [2, 0], [0, 1], [1, 1]]],
        'T' => ['size' => 3, 'cells' => [[1, 0], [0, 1], [1, 1], [2, 1]]],
        'Z' => ['size' => 3, 'cells' => [[0, 0], [1, 0], [1, 1], [2, 1]]],
    ];

    /**
     * SRS wall kicks (y-down), keyed "from>to", tried in order on each rotation.
     *
     * @var array<string, list<array{int, int}>>
     */
    private const KICKS_JLSTZ = [
        '0>1' => [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
        '1>0' => [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
        '1>2' => [[0, 0], [1, 0], [1, 1], [0, -2], [1, -2]],
        '2>1' => [[0, 0], [-1, 0], [-1, -1], [0, 2], [-1, 2]],
        '2>3' => [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
        '3>2' => [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
        '3>0' => [[0, 0], [-1, 0], [-1, 1], [0, -2], [-1, -2]],
        '0>3' => [[0, 0], [1, 0], [1, -1], [0, 2], [1, 2]],
    ];

    /** @var array<string, list<array{int, int}>> */
    private const KICKS_I = [
        '0>1' => [[0, 0], [-2, 0], [1, 0], [-2, 1], [1, -2]],
        '1>0' => [[0, 0], [2, 0], [-1, 0], [2, -1], [-1, 2]],
        '1>2' => [[0, 0], [-1, 0], [2, 0], [-1, -2], [2, 1]],
        '2>1' => [[0, 0], [1, 0], [-2, 0], [1, 2], [-2, -1]],
        '2>3' => [[0, 0], [2, 0], [-1, 0], [2, -1], [-1, 2]],
        '3>2' => [[0, 0], [-2, 0], [1, 0], [-2, 1], [1, -2]],
        '3>0' => [[0, 0], [1, 0], [-2, 0], [1, 2], [-2, -1]],
        '0>3' => [[0, 0], [-1, 0], [2, 0], [-1, -2], [2, 1]],
    ];

    /** @var array<string, list<list<array{int, int}>>>|null */
    private static ?array $shapes = null;

    /**
     * Offsets to try, in order, when rotating from one state to an adjacent one (kicksFor() in constants.js).
     *
     * @return list<array{int, int}>
     */
    public static function kicks(string $type, int $from, int $to): array
    {
        if ($type === 'O') {
            return [[0, 0]];
        }

        return ($type === 'I' ? self::KICKS_I : self::KICKS_JLSTZ)["{$from}>{$to}"];
    }

    /**
     * Cells of a piece in a rotation state (0-3, clockwise).
     *
     * @return list<array{int, int}>
     */
    public static function cells(string $type, int $rotation): array
    {
        if (self::$shapes === null) {
            self::$shapes = [];
            foreach (self::BASE as $piece => $shape) {
                $rotations = [$shape['cells']];
                for ($r = 1; $r < 4; $r++) {
                    $rotations[] = $piece === 'O'
                        ? $rotations[$r - 1]
                        : array_map(fn (array $cell): array => [$shape['size'] - 1 - $cell[1], $cell[0]], $rotations[$r - 1]);
                }
                self::$shapes[$piece] = $rotations;
            }
        }

        return self::$shapes[$type][$rotation];
    }
}
