<?php

namespace App\Game;

/**
 * Scoring and attack tables, mirroring resources/js/engine/constants.js and scoreLock() in board.js.
 */
final class Rules
{
    public const LINE_POINTS = [0, 100, 300, 500, 800];

    public const LINE_ATTACK = [0, 0, 1, 2, 4];

    public const COMBO_ATTACK = [0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 4];

    public const COMBO_POINTS = 50;

    public const B2B_ATTACK_BONUS = 1;

    public const PERFECT_CLEAR_ATTACK = 4;

    public const PERFECT_CLEAR_POINTS = 2000;

    /** Incoming garbage older than this must rise on the player's next non-clearing lock. */
    public const GARBAGE_GRACE_MS = 3000;

    /**
     * Score and attack for one lock. $combo is the counter before this lock (-1 = none).
     *
     * @return array{points: int, attack: int, combo: int, b2b: bool}
     */
    public static function scoreLock(int $lines, int $combo, bool $b2b, bool $perfectClear): array
    {
        if ($lines === 0) {
            return ['points' => 0, 'attack' => 0, 'combo' => -1, 'b2b' => $b2b];
        }

        $nextCombo = $combo + 1;
        $isTetris = $lines === 4;
        $backToBack = $isTetris && $b2b;

        $points = self::LINE_POINTS[$lines] + self::COMBO_POINTS * $nextCombo;
        $attack = self::LINE_ATTACK[$lines] + self::COMBO_ATTACK[min($nextCombo, count(self::COMBO_ATTACK) - 1)];

        if ($backToBack) {
            $points += intdiv(self::LINE_POINTS[4], 2);
            $attack += self::B2B_ATTACK_BONUS;
        }
        if ($perfectClear) {
            $points += self::PERFECT_CLEAR_POINTS;
            $attack += self::PERFECT_CLEAR_ATTACK;
        }

        return ['points' => $points, 'attack' => $attack, 'combo' => $nextCombo, 'b2b' => $isTetris];
    }
}
