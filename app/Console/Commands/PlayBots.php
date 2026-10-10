<?php

namespace App\Console\Commands;

use App\Services\BotDriver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Sleep;

#[Signature('bots:play {--seconds=0 : Stop after this many seconds (0 runs until stopped)} {--tick=50 : Milliseconds between ticks}')]
#[Description('Play the house bots\' side of every live bot match (run one instance as a daemon)')]
class PlayBots extends Command implements Isolatable
{
    public function handle(BotDriver $driver): int
    {
        $seconds = (int) $this->option('seconds');
        $until = $seconds > 0 ? now()->addSeconds($seconds) : null;
        $pieces = 0;

        $this->info('Playing bot matches'.($until ? " for {$seconds}s" : '').'...');

        while ($until === null || now()->lessThan($until)) {
            $pieces += $driver->tick();
            Sleep::for((int) $this->option('tick'))->milliseconds();
        }

        $this->info("Locked {$pieces} bot piece(s).");

        return self::SUCCESS;
    }
}
