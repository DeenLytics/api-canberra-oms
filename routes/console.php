<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Every appendOutputTo() below used to name an absolute path under
 * /var/www/canberra-api.deenlytics.com/storage/logs/. The application lives at
 * /home/canberra-api/public_html — that /var/www directory does not exist on
 * this server and never has.
 *
 * A scheduled command whose output redirect cannot be opened exits non-zero, so
 * every one of these has been failing for months: the last successful writes to
 * check-idle.log, payment-warnings.log and location-clean.log are all early
 * July, while laravel.log has recorded "failed with exit code [2]" on the hour,
 * every hour, ever since. Idle detection, payment warnings and the location
 * prune have all been dead that whole time, and the commands themselves are
 * fine — running app:check-idle by hand exits 0.
 *
 * storage_path() resolves wherever the app is actually deployed, so this cannot
 * drift again when the box or the path changes.
 */
$log = fn (string $name) => storage_path("logs/{$name}.log");

Schedule::command('app:check-idle')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->appendOutputTo($log('check-idle'));

Schedule::command('payment:warnings')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->appendOutputTo($log('payment-warnings'));

// 05:00 Asia/Dhaka, not hourly: the map should carry the working day in
// progress, so the seam belongs where the day actually starts. Before dawn
// nothing has been recorded yet and yesterday's track is complete, which is
// what makes 05:00 a clean cut rather than an arbitrary one.
//
// The timezone is stated outright. The server clock runs on CEST, so leaving it
// implicit would schedule this four hours off.
Schedule::command('location:clean --daily-reset')
    ->dailyAt('05:00')
    ->timezone('Asia/Dhaka')
    ->withoutOverlapping()
    ->appendOutputTo($log('location-clean'));
