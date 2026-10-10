<?php

namespace App\Services;

/**
 * Standard Elo rating update for a single game between two players.
 */
final class Elo
{
    public const K_FACTOR = 32;

    public const FLOOR = 100;

    /**
     * @return array{0: int, 1: int} new ratings for the winner and the loser
     */
    public static function afterGame(int $winnerRating, int $loserRating): array
    {
        $expectedWin = 1 / (1 + 10 ** (($loserRating - $winnerRating) / 400));
        $delta = (int) round(self::K_FACTOR * (1 - $expectedWin));

        return [$winnerRating + $delta, max(self::FLOOR, $loserRating - $delta)];
    }
}
