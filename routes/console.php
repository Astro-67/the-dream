<?php

use App\Support\Outbox\OutboxSweeper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('outbox:sweep', function (OutboxSweeper $sweeper): void {
    $this->info('Queued '.$sweeper->sweep().' outbox messages.');
})->purpose('Recover pending outbox delivery');

Schedule::command('outbox:sweep')->everyMinute()->withoutOverlapping();
