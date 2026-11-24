<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove login tokens that expired more than a day ago (needs `php artisan schedule:work`
// or a cron entry for `schedule:run` in production).
Schedule::command('sanctum:prune-expired --hours=24')->daily();
