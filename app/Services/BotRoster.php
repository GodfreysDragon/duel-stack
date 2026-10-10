<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The house bots that take on players nobody else is queueing against. They are ordinary
 * rated users whose names contain a space, which registration does not allow, so nobody
 * can take or imitate them.
 */
class BotRoster
{
    /**
     * @var array<string, array{name: string, rating: int}>
     */
    public const BOTS = [
        'easy' => ['name' => 'Rookie Bot', 'rating' => 800],
        'medium' => ['name' => 'Club Bot', 'rating' => 1000],
        'hard' => ['name' => 'Pro Bot', 'rating' => 1300],
    ];

    /**
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        $bots = User::query()->whereNotNull('bot_level')->get();

        foreach (array_diff_key(self::BOTS, $bots->keyBy('bot_level')->all()) as $level => $bot) {
            $bots->push(User::query()->forceCreate([
                'name' => $bot['name'],
                'password' => Str::random(64),
                'bot_level' => $level,
                'rating' => $bot['rating'],
            ]));
        }

        return $bots;
    }

    /**
     * The bot whose rating is closest to $rating.
     */
    public function closestTo(int $rating): User
    {
        return $this->all()->sortBy(fn (User $bot) => abs($bot->rating - $rating))->first();
    }
}
