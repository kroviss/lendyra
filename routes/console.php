<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('loans:accrue-penalties')->dailyAt('00:30');
Schedule::command('loans:send-reminders')->dailyAt('09:00');

if (config('lms.demo')) {
    Schedule::command('demo:reset')->dailyAt('03:00');
}
