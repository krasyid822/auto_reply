<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('instagram:process-comments')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/instagram-poll.log'));

Schedule::command('queue:work database --tries=3 --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));
