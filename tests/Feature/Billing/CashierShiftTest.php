<?php

use App\Domains\Billing\Actions\CloseCashierShift;
use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ResolvePostingApproval;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Actions\SetApprovalThreshold;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

/** A folio on a new reservation in the property in context. */
function cashierShiftFolio(User $owner): Folio
{
    $id = app(CreateReservation::class)->handle($owner, BookingForm::details($owner))->id;

    return Folio::query()->where('reservation_id', $id)->sole();
}

test('opening a shift records a float in each currency, one open shift per user and property', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $shift = app(OpenCashierShift::class)->handle($owner, ['TZS' => 500000, 'USD' => 10000]);

        expect($shift->status)->toBe('open')->and($shift->user_id)->toBe($owner->id)
            ->and($shift->floats)->toEqual(['TZS' => 500000, 'USD' => 10000]);
        expect(fn () => app(OpenCashierShift::class)->handle($owner, ['USD' => 100]))->toThrow(ValidationException::class);
        expect(Activity::query()->where('description', 'Cashier shift open refused')->where('event', 'refused')->sole()->getExtraProperty('result'))->toBe('refused');
    });
});

test('a cash payment needs an open shift of the cashier recording it, and counts towards it', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = cashierShiftFolio($owner);
        $cash = ['method' => 'cash', 'amount' => 3000, 'currency' => 'USD'];

        expect(fn () => app(RecordPayment::class)->handle($owner, $folio, $cash))->toThrow(ValidationException::class);
        expect(Posting::query()->count())->toBe(0);

        $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 10000]);
        $payment = app(RecordPayment::class)->handle($owner, $folio, $cash);

        expect($payment->method)->toBe('cash')->and($payment->cashier_shift_id)->toBe($shift->id)
            ->and($payment->receipt?->number)->toBe('RCT-000001')
            ->and($shift->expectedCash())->toEqual(['USD' => 13000]);
    });
});

test('closing counts cash per currency, a variance needs a reason, and a closed shift takes no cash', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $folio = cashierShiftFolio($owner);
        $shift = app(OpenCashierShift::class)->handle($owner, ['TZS' => 50000, 'USD' => 10000]);
        app(RecordPayment::class)->handle($owner, $folio, ['method' => 'cash', 'amount' => 3000, 'currency' => 'USD']);

        expect(fn () => app(CloseCashierShift::class)->handle($owner, $shift, ['TZS' => 50000, 'USD' => 12500], null))->toThrow(ValidationException::class);
        expect($shift->fresh()->status)->toBe('open');

        $closed = app(CloseCashierShift::class)->handle($owner, $shift, ['TZS' => 50000, 'USD' => 12500], 'Change given twice');

        expect($closed->status)->toBe('closed')
            ->and($closed->expected)->toEqual(['TZS' => 50000, 'USD' => 13000])
            ->and($closed->counted)->toEqual(['TZS' => 50000, 'USD' => 12500])
            ->and($closed->variance())->toEqual(['TZS' => 0, 'USD' => -500])
            ->and($closed->variance_reason)->toBe('Change given twice');
        expect(fn () => app(RecordPayment::class)->handle($owner, $folio, ['method' => 'cash', 'amount' => 100, 'currency' => 'USD']))->toThrow(ValidationException::class);
        expect(fn () => app(CloseCashierShift::class)->handle($owner, $closed, ['TZS' => 50000, 'USD' => 13000], null))->toThrow(ValidationException::class);
        // A new shift opens once the last one is closed.
        expect(app(OpenCashierShift::class)->handle($owner, ['USD' => 0])->status)->toBe('open');
    });
});

test('a shift whose count matches closes without a reason', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 10000]);

        $closed = app(CloseCashierShift::class)->handle($owner, $shift, ['USD' => 10000], null);

        expect($closed->variance())->toEqual(['USD' => 0])->and($closed->variance_reason)->toBeNull();
    });
});

test('reversing a cash payment corrects its drawer before the shift closes', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 0]);
        $folio = cashierShiftFolio($owner);
        $payment = app(RecordPayment::class)->handle($owner, $folio, ['method' => 'cash', 'amount' => 1000, 'currency' => 'USD']);

        $reversal = app(ReversePosting::class)->handle($owner, $payment, 'Entered twice');

        expect($reversal->method)->toBe('cash')->and($reversal->cashier_shift_id)->toBe($shift->id)
            ->and($folio->fresh()->balance()->amount)->toBe(0)
            ->and($shift->fresh()->expectedCash())->toEqual(['USD' => 0]);
        expect(app(CloseCashierShift::class)->handle($owner, $shift, ['USD' => 0], null)->variance())->toEqual(['USD' => 0]);
    });
});

test('cash reversals cannot alter a closed drawer count', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, function () use ($owner) {
        $shift = app(OpenCashierShift::class)->handle($owner, ['USD' => 0]);
        $payment = app(RecordPayment::class)->handle($owner, cashierShiftFolio($owner), ['method' => 'cash', 'amount' => 1000, 'currency' => 'USD']);
        app(CloseCashierShift::class)->handle($owner, $shift, ['USD' => 1000], null);

        expect(fn () => app(ReversePosting::class)->handle($owner, $payment, 'Wrong entry'))
            ->toThrow(ValidationException::class);
        expect($shift->fresh()->expectedCash())->toEqual(['USD' => 1000])->and(Posting::query()->count())->toBe(1);
    });
});

test('approved cash corrections retain the original cashier drawer even when requested by finance', function () {
    $t = twoTenants();
    $finance = $t->a->member(OrganizationRole::Finance);
    inTenant($t->a->property, function () use ($t, $finance) {
        $shift = app(OpenCashierShift::class)->handle($t->a->owner, ['USD' => 0]);
        $folio = cashierShiftFolio($t->a->owner);
        $payment = app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'cash', 'amount' => 1000, 'currency' => 'USD']);
        app(SetApprovalThreshold::class)->handle($t->a->owner, 'USD', 500);
        $approval = app(ReversePosting::class)->handle($finance, $payment, 'Wrong entry');
        expect($approval)->toBeInstanceOf(PostingApproval::class);
        expect($shift->fresh()->expectedCash())->toEqual(['USD' => 1000]);

        app(ResolvePostingApproval::class)->handle($t->a->owner, $approval, 'approve');

        expect($shift->fresh()->expectedCash())->toEqual(['USD' => 0])
            ->and($folio->fresh()->balance()->amount)->toBe(0);
    });
});
