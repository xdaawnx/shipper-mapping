<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

// Schedule::command('app:create-template-ninja-express')
//              ->everyTenSeconds()
//              ->withoutOverlapping(); // Ensures no overlapping jobs

Schedule::command('app:upload-files')
            // ->hourly()
            ->everyTenMinutes()
            // ->everyThirtySeconds()
            ->withoutOverlapping(); // Ensures no overlapping jobs
