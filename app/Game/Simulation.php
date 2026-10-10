<?php

namespace App\Game;

/**
 * Server-side port of applyLock() in resources/js/engine/simulation.js.
 *
 * The client runs gravity, input and rotation kicks; the server only sees
 * where each piece came to rest and checks that this is a legal outcome:
 * the right piece (given the seeded sequence and any hold), inside the
 * field, not overlapping the stack, and resting on something.
 */
final class Simulation
{
    /**
     * @param  array{piece: string, rotation: int, x: int, y: int, held: bool, garbage: list<array{lines: int, hole: int}>}  $lock
     * @return array{state: PlayerState, cleared: list<int>, attack: int, points: int}
     *
     * @throws InvalidLock
     */
    public static function applyLock(PlayerState $state, Randomizer $randomizer, array $lock): array
    {
        if ($state->toppedOut) {
            throw new InvalidLock('Player has already topped out.');
        }

        $current = $state->current;
        $hold = $state->hold;
        $nextIndex = $state->nextIndex;

        if ($lock['held']) {
            if ($hold === null) {
                $hold = $current;
                $current = $randomizer->piece($nextIndex);
                $nextIndex++;
            } else {
                [$current, $hold] = [$hold, $current];
            }
        }

        ['piece' => $piece, 'rotation' => $rotation, 'x' => $x, 'y' => $y] = $lock;

        if ($piece !== $current) {
            throw new InvalidLock("Expected piece {$current}, got {$piece}.");
        }
        if ($rotation < 0 || $rotation > 3) {
            throw new InvalidLock('Invalid rotation.');
        }
        if (! $state->board->fits($piece, $rotation, $x, $y)) {
            throw new InvalidLock('Piece overlaps the stack or the walls.');
        }
        if (! $state->board->isGrounded($piece, $rotation, $x, $y)) {
            throw new InvalidLock('Piece is floating.');
        }

        [$board, $cleared] = $state->board->place($piece, $rotation, $x, $y)->clearLines();
        $perfectClear = $cleared !== [] && $board->isEmpty();
        $scored = Rules::scoreLock(count($cleared), $state->combo, $state->b2b, $perfectClear);

        if ($cleared !== [] && $lock['garbage'] !== []) {
            throw new InvalidLock('Garbage can only rise on a lock that clears no lines.');
        }

        $toppedOut = Board::lockedOut($piece, $rotation, $y);
        foreach ($lock['garbage'] as $batch) {
            [$board, $overflow] = $board->addGarbage($batch['lines'], $batch['hole']);
            $toppedOut = $toppedOut || $overflow;
        }

        $next = $randomizer->piece($nextIndex);
        $toppedOut = $toppedOut || ! $board->spawnFits($next);

        return [
            'state' => new PlayerState(
                board: $board,
                current: $next,
                nextIndex: $nextIndex + 1,
                hold: $hold,
                combo: $scored['combo'],
                b2b: $scored['b2b'],
                score: $state->score + $scored['points'],
                lines: $state->lines + count($cleared),
                pieces: $state->pieces + 1,
                toppedOut: $toppedOut,
            ),
            'cleared' => $cleared,
            'attack' => $scored['attack'],
            'points' => $scored['points'],
        ];
    }
}
