<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('loans:accrue-penalties')->dailyAt('00:30');
