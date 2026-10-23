<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MatchmakingController;
use App\Http\Controllers\PlayerController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->name('api.')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register');
    Route::post('guest', [AuthController::class, 'guest'])->middleware('throttle:10,1')->name('guest');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('leaderboard', LeaderboardController::class)->name('leaderboard');
    Route::get('players/{user:name}', [PlayerController::class, 'show'])->name('players.show');
    Route::get('players/{user:name}/matches', [PlayerController::class, 'matches'])->name('players.matches');
    Route::get('matches/{match}/replay', [MatchController::class, 'replay'])->name('matches.replay');

    Route::middleware('auth')->group(function () {
        Route::post('matchmaking', [MatchmakingController::class, 'store'])->name('matchmaking.join');
        Route::delete('matchmaking', [MatchmakingController::class, 'destroy'])->name('matchmaking.leave');

        Route::get('matches/{match}', [MatchController::class, 'show'])->name('matches.show');
        Route::post('matches/{match}/locks', [MatchController::class, 'lock'])->middleware('throttle:locks')->name('matches.lock');
        Route::post('matches/{match}/forfeit', [MatchController::class, 'forfeit'])->name('matches.forfeit');
        Route::post('matches/{match}/heartbeat', [MatchController::class, 'heartbeat'])->middleware('throttle:locks')->name('matches.heartbeat');
        Route::post('matches/{match}/claim', [MatchController::class, 'claim'])->name('matches.claim');
    });
});

// Everything else is the Vue SPA (history-mode routing).
Route::view('/{any?}', 'app')->where('any', '^(?!api/|broadcasting/|up$).*')->name('app');
