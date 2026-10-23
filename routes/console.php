<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('matches:expire-idle')->everyMinute()->withoutOverlapping();
Schedule::command('ratings:decay')->dailyAt('03:00');
Schedule::command('seasons:roll')->monthlyOn(1, '00:00');
