<?php

namespace App\Game;

/**
 * Which positions a piece can be steered to from its spawn point with real inputs: shifts, soft drops and
 * clockwise or counter-clockwise rotations with SRS kicks (a 180 is two clockwise turns). Timing is ignored,
 * so this is every position a player could reach, given enough time before the piece locks.
 *
 * The server uses it to reject locks that no sequence of inputs could produce, such as a piece placed inside
 * a sealed cavity.
 */
final class Reachability
{
    /**
     * Every reachable position of $type on $board, keyed "rotation:x:y".
     *
     * @return array<string, true>
     */
    public static function positions(Board $board, string $type): array
    {
        if (! $board->fits($type, 0, Pieces::SPAWN_X, Pieces::SPAWN_Y)) {
            return [];
        }

        $start = [0, Pieces::SPAWN_X, Pieces::SPAWN_Y];
        $seen = [self::key(...$start) => true];
        $queue = [$start];

        while ($queue !== []) {
            [$rotation, $x, $y] = array_pop($queue);

            $moves = [[$rotation, $x - 1, $y], [$rotation, $x + 1, $y], [$rotation, $x, $y + 1]];
            foreach ([1, 3] as $turn) {
                $to = ($rotation + $turn) % 4;
                foreach (Pieces::kicks($type, $rotation, $to) as [$kx, $ky]) {
                    if ($board->fits($type, $to, $x + $kx, $y + $ky)) {
                        $moves[] = [$to, $x + $kx, $y + $ky];
                        break;
                    }
                }
            }

            foreach ($moves as $move) {
                $key = self::key(...$move);
                if (! isset($seen[$key]) && $board->fits($type, ...$move)) {
                    $seen[$key] = true;
                    $queue[] = $move;
                }
            }
        }

        return $seen;
    }

    public static function canReach(Board $board, string $type, int $rotation, int $x, int $y): bool
    {
        return isset(self::positions($board, $type)[self::key($rotation, $x, $y)]);
    }

    public static function key(int $rotation, int $x, int $y): string
    {
        return "{$rotation}:{$x}:{$y}";
    }
}
