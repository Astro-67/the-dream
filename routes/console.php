<?php

use App\Models\User;
use App\Support\Outbox\OutboxRecovery;
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

Artisan::command('outbox:retry {message} {--actor=} {--property=} {--reason=}', function (OutboxRecovery $recovery): int {
    $actor = User::query()->find($this->option('actor'));
    if ($actor === null || blank($this->option('property')) || blank($this->option('reason'))) {
        $this->error('Actor, property and reason are required.');

        return self::FAILURE;
    }
    try {
        $recovery->retry($actor, $this->option('property'), $this->argument('message'), $this->option('reason'));
        $this->info('Recovery scheduled.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Restart a safe outbox retry');

Artisan::command('outbox:correct {message} {--actor=} {--property=} {--reason=} {--payload=}', function (OutboxRecovery $recovery): int {
    $actor = User::query()->find($this->option('actor'));
    $payload = json_decode((string) $this->option('payload'), true);
    if ($actor === null || blank($this->option('property')) || blank($this->option('reason')) || ! is_array($payload)) {
        $this->error('Actor, property, reason and JSON correction payload are required.');

        return self::FAILURE;
    }
    try {
        $recovery->correct($actor, $this->option('property'), $this->argument('message'), $this->option('reason'), $payload);
        $this->info('Correction scheduled.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Correct a confirmed rejected outbox operation');

Artisan::command('outbox:correct {message} {--actor=} {--property=} {--reason=} {--payload=}', function (OutboxRecovery $recovery): int {
    $actor = User::query()->find($this->option('actor'));
    $payload = json_decode((string) $this->option('payload'), true);
    if ($actor === null || blank($this->option('property')) || blank($this->option('reason')) || ! is_array($payload)) {
        $this->error('Actor, property, reason and JSON correction payload are required.');

        return self::FAILURE;
    }
    try {
        $recovery->correct($actor, $this->option('property'), $this->argument('message'), $this->option('reason'), $payload);
        $this->info('Correction scheduled.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Correct a confirmed rejected outbox operation');

Artisan::command('outbox:confirm-accepted {message} {--actor=} {--property=} {--reason=} {--evidence=}', function (OutboxRecovery $recovery): int {
    $actor = User::query()->find($this->option('actor'));
    if ($actor === null || blank($this->option('property')) || blank($this->option('reason')) || blank($this->option('evidence'))) {
        $this->error('Actor, property, reason and acceptance evidence are required.');

        return self::FAILURE;
    }
    try {
        $recovery->confirmAccepted($actor, $this->option('property'), $this->argument('message'), $this->option('reason'), $this->option('evidence'));
        $this->info('Acceptance recorded.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Record evidence-backed outbox acceptance');

Artisan::command('outbox:show-body {message} {--actor=} {--property=} {--reason=}', function (OutboxRecovery $recovery): int {
    $actor = User::query()->find($this->option('actor'));
    if ($actor === null || blank($this->option('property')) || blank($this->option('reason'))) {
        $this->error('Actor, property and reason are required.');

        return self::FAILURE;
    }
    try {
        $this->line(json_encode($recovery->showBody($actor, $this->option('property'), $this->argument('message'), $this->option('reason')), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Read an outbox payload with separate authorization');

Schedule::command('outbox:sweep')->everyMinute()->withoutOverlapping();
