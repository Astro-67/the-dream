<?php

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Outbox\OutboxPropertyLookup;
use App\Support\Outbox\OutboxRecovery;
use App\Support\Outbox\OutboxSweeper;
use App\Support\Outbox\RestoreGate;
use App\Support\Outbox\RestoreIdentityRecovery;
use App\Support\Outbox\RestoreReconciler;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('outbox:gate {--actor=} {--property=} {--reason=}', function (RestoreGate $gate): int {
    $actor = User::query()->find($this->option('actor'));
    $property = app(OutboxPropertyLookup::class)->find((string) $this->option('property'));
    $reason = trim((string) $this->option('reason'));
    if ($actor === null || $property === null || $reason === '') {
        $this->error('Actor, property and reason are required.');

        return self::FAILURE;
    }
    try {
        Tenancy::within(TenantContext::forProperty($property), function () use ($actor, $property, $reason, $gate): void {
            if (! Access::allows($actor, OrganizationPermission::OutboxRecover, $property)) {
                throw new RuntimeException('Outbox recovery is not authorized.');
            }
            DB::transaction(function () use ($actor, $property, $reason, $gate): void {
                $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
                $gate->close($locked, $reason, $actor);
            });
        });
        $this->info('Restore gate closed.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Close the persistent outbox restore gate');

Artisan::command('outbox:reconcile-after-restore {--actor=} {--property=} {--reason=}', function (RestoreReconciler $reconciler): int {
    $actor = User::query()->find($this->option('actor'));
    $property = app(OutboxPropertyLookup::class)->find((string) $this->option('property'));
    $reason = trim((string) $this->option('reason'));
    if ($actor === null || $property === null || $reason === '') {
        $this->error('Actor, property and reason are required.');

        return self::FAILURE;
    }
    try {
        $report = $reconciler->reconcile($actor, $property, $reason);
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        if ($report['unresolved'] > 0) {
            $this->error('Unresolved outbox work remains; gate stays closed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Reconcile outbox delivery after restore');

Artisan::command('outbox:identity-safe {--actor=} {--property=} {--reason=}', function (RestoreIdentityRecovery $recovery): int {
    $actor = User::query()->find($this->option('actor'));
    $property = app(OutboxPropertyLookup::class)->find((string) $this->option('property'));
    $reason = trim((string) $this->option('reason'));
    if ($actor === null || $property === null || $reason === '') {
        $this->error('Actor, property and reason are required.');

        return self::FAILURE;
    }
    try {
        Tenancy::within(TenantContext::forProperty($property), function () use ($actor, $property): void {
            if (! Access::allows($actor, OrganizationPermission::OutboxRecover, $property)) {
                throw new RuntimeException('Outbox recovery is not authorized.');
            }
        });
        $count = Tenancy::within(TenantContext::forProperty($property), fn () => $recovery->recover($property, $actor, $reason));
        $this->info("Recovered {$count} verified identity boundaries.");

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Recover verified outbox identity boundaries');

Artisan::command('outbox:resume {--actor=} {--property=} {--reason=}', function (RestoreGate $gate): int {
    $actor = User::query()->find($this->option('actor'));
    $property = app(OutboxPropertyLookup::class)->find((string) $this->option('property'));
    $reason = trim((string) $this->option('reason'));
    if ($actor === null || $property === null || $reason === '') {
        $this->error('Actor, property and reason are required.');

        return self::FAILURE;
    }
    try {
        Tenancy::within(TenantContext::forProperty($property), function () use ($actor, $property, $reason, $gate): void {
            if (! Access::allows($actor, OrganizationPermission::OutboxRecover, $property)) {
                throw new RuntimeException('Outbox recovery is not authorized.');
            }
            DB::transaction(function () use ($actor, $property, $reason, $gate): void {
                $gate->open(Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail(), $actor, $reason);
            });
        });
        $this->info('Restore gate reopened.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Reopen the outbox restore gate after verified recovery');

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
