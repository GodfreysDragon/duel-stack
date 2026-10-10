<?php

namespace App\Game;

use RuntimeException;

/**
 * Thrown when a submitted lock is not a legal move from the player's current state.
 */
class InvalidLock extends RuntimeException {}
