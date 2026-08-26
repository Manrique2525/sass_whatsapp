<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('whatsapp:reprocess-webhook-events')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('flow:fire-schedule-triggers')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('analytics:aggregate-daily')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('scheduler:heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('audit:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command('queue:prune-failed')
    ->dailyAt('03:00')
    ->withoutOverlapping();
