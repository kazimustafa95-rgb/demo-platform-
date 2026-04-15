<?php

use App\Jobs\MaintainCommunityEngagement;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('demos:sync-federal')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('demos:sync-federal --with-state')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes();

Schedule::call(function (): void {
    app(MaintainCommunityEngagement::class)->handle();
})
    ->name('maintain-community-engagement')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
