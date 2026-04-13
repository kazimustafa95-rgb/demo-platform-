<?php

use App\Jobs\MaintainCommunityEngagement;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('demos:sync-federal --now')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('demos:sync-federal --now --with-state')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::call(fn () => app(MaintainCommunityEngagement::class)->handle())
    ->name('maintain-community-engagement')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
