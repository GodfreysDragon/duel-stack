<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('matches:expire-idle')->everyMinute()->withoutOverlapping();
