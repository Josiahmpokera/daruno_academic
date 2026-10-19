<?php

use Illuminate\Foundation\Inspiring;
use App\Services\Communication\Publisher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('communication:publish-due', function () {
    $n = Publisher::publishDue();
    $this->info("Published {$n} scheduled item(s).");
})->purpose('Publish scheduled announcements and notices whose time has come');

Schedule::command('communication:publish-due')->everyMinute()->withoutOverlapping();
