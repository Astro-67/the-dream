<?php

use App\Domains\FiscalReceipting\Actions\CloseWithoutFiling;
use App\Domains\FrontDesk\Actions\RunAutomaticCheckout;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Jobs\CloseDueBusinessDate;
use App\Domains\Housekeeping\Actions\SyncRoomServiceStatus;
use App\Domains\Inventory\Actions\RecalculateInventory;
use App\Domains\Inventory\Exceptions\InventoryRefused;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Reservations\Actions\CancelUnpaidReservations;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Subscriptions\Actions\AdvanceSubscription;
use App\Domains\Subscriptions\Models\Subscription;
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

Artisan::command('outbox:close-without-filing {reservation : Full reservation number} {--actor=} {--property=} {--reason=}', function (CloseWithoutFiling $close): int {
    $actor = User::query()->find($this->option('actor'));
    $property = app(OutboxPropertyLookup::class)->find((string) $this->option('property'));
    $reason = trim((string) $this->option('reason'));
    if ($actor === null || $property === null || $reason === '') {
        $this->error('Actor, property and reason are required.');

        return self::FAILURE;
    }
    try {
        $number = (string) $this->argument('reservation');
        Tenancy::within(TenantContext::forProperty($property), function () use ($actor, $property, $reason, $number, $close): void {
            $reservation = Reservation::query()->where('property_id', $property->id)->where('reservation_number', $number)->firstOrFail();
            $close->handle($actor, $reservation, $reason);
        });
        $this->info('Closed '.$number.' without filing; fiscal exception recorded.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Close a departed unfiled stay and record its fiscal exception');

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

Artisan::command('inventory:extend', function (RecalculateInventory $recalculate): int {
    $properties = Tenancy::within(TenantContext::platform('daily inventory horizon extension'), fn () => Property::query()->active()->get());
    $refused = 0;
    foreach ($properties as $property) {
        Tenancy::within(TenantContext::forProperty($property), function () use ($recalculate, $property, &$refused): void {
            // A refused room type is left as it was and reported; the rest still extend.
            RoomType::query()->each(function (RoomType $roomType) use ($recalculate, $property, &$refused): void {
                try {
                    $recalculate->handle($roomType);
                } catch (InventoryRefused $refusal) {
                    $refused++;
                    $this->error("{$property->name}: {$refusal->getMessage()}");
                }
            });
        });
    }
    $this->info('Extended inventory for '.$properties->count().' properties.');

    return $refused === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Extend inventory records to the configured horizon');

Schedule::command('inventory:extend')->daily()->withoutOverlapping();
Schedule::command('outbox:sweep')->everyMinute()->withoutOverlapping();

Artisan::command('housekeeping:sync-service', function (SyncRoomServiceStatus $sync): int {
    $properties = Tenancy::within(TenantContext::platform('housekeeping service-period rollover'), fn () => Property::query()->active()->get());
    foreach ($properties as $property) {
        Tenancy::within(TenantContext::forProperty($property), function () use ($sync): void {
            Room::query()->each(fn (Room $room) => $sync->handle(null, $room));
        });
    }

    return self::SUCCESS;
})->purpose('Reconcile housekeeping with property-local service periods');

Schedule::command('housekeeping:sync-service')->everyMinute()->withoutOverlapping();

Artisan::command('night-audit:close-due', function (): int {
    $properties = Tenancy::within(TenantContext::platform('night audit trigger'), fn () => Property::query()->active()->get());
    $failed = 0;
    foreach ($properties as $property) {
        if (! BusinessDate::isDue($property)) {
            continue;
        }
        $date = BusinessDate::of($property);
        try {
            Tenancy::within(TenantContext::forProperty($property), function () use ($date): void {
                CloseDueBusinessDate::dispatch($date);
            });
        } catch (Throwable $exception) {
            // One property's failure must not stop the others closing.
            report($exception);
            $failed++;
            $this->error("{$property->name}: {$exception->getMessage()}");
        }
    }

    return $failed === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Dispatch night audit for properties past their local close time');

Schedule::command('night-audit:close-due')->everyMinute()->withoutOverlapping();

Artisan::command('front-desk:checkout-due', function (RunAutomaticCheckout $checkout): int {
    $properties = Tenancy::within(TenantContext::platform('automatic check-out trigger'), fn () => Property::query()->active()->get());
    $failed = 0;
    foreach ($properties as $property) {
        try {
            Tenancy::within(TenantContext::forProperty($property), fn () => $checkout->handle($property));
        } catch (Throwable $exception) {
            report($exception);
            $failed++;
            $this->error("{$property->name}: {$exception->getMessage()}");
        }
    }

    return $failed === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Check out due stays after each property local automatic check-out time');

Schedule::command('front-desk:checkout-due')->everyMinute()->withoutOverlapping();

Artisan::command('subscriptions:advance', function (AdvanceSubscription $advance): int {
    $organizations = Tenancy::within(TenantContext::platform('hourly subscription lifecycle'), fn () => Subscription::query()->pluck('organization_id'));
    $failed = 0;
    foreach ($organizations as $organizationId) {
        try {
            Tenancy::within(TenantContext::forOrganization($organizationId), fn () => $advance->handle());
        } catch (Throwable $exception) {
            report($exception);
            $failed++;
            $this->error("{$organizationId}: {$exception->getMessage()}");
        }
    }

    return $failed === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Issue subscription invoices, send reminders and advance unpaid subscriptions');

Schedule::command('subscriptions:advance')->hourly()->withoutOverlapping();

Artisan::command('reservations:cancel-unpaid', function (): int {
    $properties = Tenancy::within(TenantContext::platform('automatic unpaid reservation cancellation'), fn () => Property::query()->active()->get());
    $failed = 0;
    foreach ($properties as $property) {
        try {
            Tenancy::within(TenantContext::forProperty($property), function () use ($property): void {
                app(CancelUnpaidReservations::class)->handle($property);
            });
        } catch (Throwable $exception) {
            report($exception);
            $failed++;
            $this->error("{$property->name}: {$exception->getMessage()}");
        }
    }

    return $failed === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Cancel reservations still unpaid after the property deadline');

Schedule::command('reservations:cancel-unpaid')->everyMinute()->withoutOverlapping();
