<?php

namespace App\Game;

/**
 * Server-side port of chooseMove() in resources/js/engine/bot.js: scores every hard-drop
 * placement of the current piece and of the hold swap, and picks the best one.
 */
final class Bot
{
    /** Weights from Yiyuan Lee's "Tetris AI – The (Near) Perfect Bot" (El-Tetris style features). */
    private const WEIGHTS = ['height' => -0.510066, 'lines' => 0.760666, 'holes' => -0.35663, 'bumpiness' => -0.184483];

    /**
     * Average speed and error rate per level, matching DIFFICULTIES in bot.js. Medium plays at a casual
     * human's pace; hard at a practised one's.
     *
     * @var array<string, array{pieces_per_second: float, mistake_rate: float}>
     */
    public const LEVELS = [
        'easy' => ['pieces_per_second' => 0.6, 'mistake_rate' => 0.4],
        'medium' => ['pieces_per_second' => 0.9, 'mistake_rate' => 0.25],
        'hard' => ['pieces_per_second' => 1.6, 'mistake_rate' => 0.08],
    ];

    /** Chance that a piece gets a long hesitation, and how many average pieces' time it adds. */
    private const HESITATION = ['chance' => 0.1, 'length' => 1.0];

    /**
     * How long the bot takes over a piece, like a person: anywhere from half to 1.3 times its average,
     * now and then with a long pause on top, averaging out at its level's pace. The same match and piece
     * always give the same time, so a stateless driver can tell when the piece is due.
     */
    public static function thinkMilliseconds(string $level, int $seed, int $pieceNumber): int
    {
        $roll = fn (int $salt): float => (crc32("{$seed}:{$pieceNumber}:{$salt}") % 1_000_000) / 1_000_000;
        $factor = 0.5 + 0.8 * $roll(0) + ($roll(1) < self::HESITATION['chance'] ? self::HESITATION['length'] : 0.0);

        return (int) round($factor * 1000 / self::LEVELS[$level]['pieces_per_second']);
    }

    /**
     * A lock for the current turn, or null if nothing fits.
     *
     * @param  (callable(): float)|null  $random  returns a float in [0, 1)
     * @return array{piece: string, rotation: int, x: int, y: int, held: bool}|null
     */
    public static function chooseMove(PlayerState $state, Randomizer $randomizer, float $mistakeRate = 0.0, ?callable $random = null): ?array
    {
        $random ??= fn (): float => mt_rand() / (mt_getrandmax() + 1);

        $options = self::placements($state->board, $state->current, held: false);
        $swapped = $state->hold ?? $randomizer->piece($state->nextIndex);
        if ($swapped !== $state->current) {
            $options = [...$options, ...self::placements($state->board, $swapped, held: true)];
        }
        if ($options === []) {
            return null;
        }

        usort($options, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        // Weaker bots sometimes settle for a lesser move.
        $pick = $random() < $mistakeRate
            ? $options[min(count($options) - 1, 1 + (int) floor($random() * 4))]
            : $options[0];
        unset($pick['score']);

        return $pick;
    }

    /**
     * Higher is better: low, flat stacks without holes, and cleared lines.
     */
    public static function evaluate(Board $board, int $linesCleared): float
    {
        $heights = $board->columnHeights();
        $bumpiness = 0;
        for ($x = 0; $x < Board::WIDTH - 1; $x++) {
            $bumpiness += abs($heights[$x] - $heights[$x + 1]);
        }

        return self::WEIGHTS['height'] * array_sum($heights)
            + self::WEIGHTS['lines'] * $linesCleared
            + self::WEIGHTS['holes'] * $board->holes()
            + self::WEIGHTS['bumpiness'] * $bumpiness;
    }

    /**
     * Every distinct hard-drop placement of $type. Unlike the JS bot, placements the server would reject as
     * unreachable (only possible with blocks in the spawn rows) are left out, so the bot never retries one.
     *
     * @return list<array{piece: string, rotation: int, x: int, y: int, held: bool, score: float}>
     */
    private static function placements(Board $board, string $type, bool $held): array
    {
        $reachable = Reachability::positions($board, $type);
        $seen = [];
        $result = [];
        for ($rotation = 0; $rotation < 4; $rotation++) {
            for ($x = -2; $x < Board::WIDTH; $x++) {
                if (! $board->fits($type, $rotation, $x, Pieces::SPAWN_Y)) {
                    continue;
                }
                $y = $board->dropY($type, $rotation, $x, Pieces::SPAWN_Y);
                if (! isset($reachable[Reachability::key($rotation, $x, $y)])) {
                    continue;
                }
                $placed = $board->place($type, $rotation, $x, $y);
                $key = $placed->toString();
                if (isset($seen[$key])) {
                    continue; // O piece and symmetric rotations
                }
                $seen[$key] = true;

                [$after, $cleared] = $placed->clearLines();
                $result[] = [
                    'piece' => $type,
                    'rotation' => $rotation,
                    'x' => $x,
                    'y' => $y,
                    'held' => $held,
                    'score' => self::evaluate($after, count($cleared)),
                ];
            }
        }

        return $result;
    }
}
