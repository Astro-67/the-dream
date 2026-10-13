<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

test('opening a financial record rolls back when its audit cannot be written', function (string $kind) {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner, $kind) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $model = $kind === 'folio' ? Folio::class : CashierShift::class;
        $before = $model::query()->count();
        $failAudit = true;
        Activity::creating(function (Activity $activity) use (&$failAudit) {
            if ($failAudit && in_array($activity->description, ['Folio opened', 'Cashier shift opened'], true)) {
                throw new RuntimeException('Audit unavailable');
            }
        });
        $open = fn () => $kind === 'folio'
            ? app(OpenFolio::class)->handle($owner, $reservation, 'USD')
            : app(OpenCashierShift::class)->handle($owner, ['USD' => 0]);
        try {
            expect($open)->toThrow(RuntimeException::class, 'Audit unavailable');
            expect($model::query()->count())->toBe($before);
        } finally {
            $failAudit = false;
        }
        $record = $open();
        expect($model::query()->count())->toBe($before + 1)
            ->and(Activity::query()->where('subject_id', $record->id)->where('event', 'created')->exists())->toBeTrue();
    });
})->with(['folio', 'shift']);
