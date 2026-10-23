<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::command('ruangan:update-status')
    ->everyMinute();
Schedule::command('surat-masuk:proses-pengingat')
    ->everyMinute()
    ->withoutOverlapping();