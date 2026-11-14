
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Revenue Management
|--------------------------------------------------------------------------
|
| Calculate overdue invoice balances daily at 00:10 Ethiopia time.
|
*/

Schedule::command('revenue:calculate-overdue-balances')
    ->dailyAt('00:10')
    ->timezone('Africa/Addis_Ababa')
    ->withoutOverlapping(60)
    ->onOneServer();
