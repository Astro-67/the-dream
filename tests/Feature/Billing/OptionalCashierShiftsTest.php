<?php

use App\Domains\Billing\Actions\CloseCashierShift;
use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ResolvePostingApproval;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Actions\SetApprovalThreshold;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;

test('cash payments without shifts keep their actor receipt audit and idempotency', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);

    inTenant($property, function () use ($owner) {
        app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $folio = Folio::query()->sole();
        $details = ['method' => 'cash', 'currency' => 'USD', 'amount' => 1500, 'idempotency_key' => 'cash-no-shift'];
        $payment = app(RecordPayment::class)->handle($owner, $folio, $details);
        $again = app(RecordPayment::class)->handle($owner, $folio, $details);

        expect($again->id)->toBe($payment->id)
            ->and($payment->actor_id)->toBe($owner->id)
            ->and($payment->method)->toBe('cash')
            ->and($payment->cashier_shift_id)->toBeNull()
            ->and($payment->receipt)->not->toBeNull()
            ->and($folio->balance()->amount)->toBe(-1500)
            ->and(CashierShift::query()->exists())->toBeFalse()
            ->and(Activity::query()->where('subject_id', $payment->id)->where('causer_id', $owner->id)->where('description', 'Payment recorded')->count())->toBe(1);
    });
});

test('closed shift approval safeguards survive turning shifts off', function () {
    $t = twoTenants();
    $property = $t->a->property;
    inTenant($property, function () use ($t, $property) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $shift = app(OpenCashierShift::class)->handle($t->a->owner, ['USD' => 0]);
        $payment = app(RecordPayment::class)->handle($t->a->owner, Folio::query()->sole(), ['method' => 'cash', 'currency' => 'USD', 'amount' => 1500]);
        app(SetApprovalThreshold::class)->handle($t->a->owner, 'USD', 500);
        $approval = app(ReversePosting::class)->handle($t->a->member(OrganizationRole::Finance), $payment, 'Correction');
        app(CloseCashierShift::class)->handle($t->a->owner, $shift, ['USD' => 1500], null);
        $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);

        expect(fn () => app(ResolvePostingApproval::class)->handle($t->a->owner, $approval, 'approve'))->toThrow(ValidationException::class);
        expect($approval->fresh()->status)->toBe('pending')
            ->and($payment->fresh()->cashier_shift_id)->toBe($shift->id)
            ->and($shift->fresh()->expectedCash())->toEqual(['USD' => 1500]);
    });
});

test('an unshifted cash refund awaiting approval respects a later decision to require shifts', function (bool $enabled) {
    $t = twoTenants();
    $property = $t->a->property;
    $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);
    inTenant($property, function () use ($t, $property, $enabled) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        app(SetApprovalThreshold::class)->handle($t->a->owner, 'USD', 500);
        $approval = app(RecordAdjustment::class)->handle($t->a->owner, Folio::query()->sole(), 'refund', [
            'method' => 'cash', 'amount' => 1500, 'reason' => 'Refund', 'idempotency_key' => 'pending-cash',
        ]);
        expect($approval)->toBeInstanceOf(PostingApproval::class);
        $property->update(['payment_settings' => ['use_cashier_shifts' => $enabled]]);
        $approver = $t->a->member(OrganizationRole::PropertyManager);
        if ($enabled) {
            expect(fn () => app(ResolvePostingApproval::class)->handle($approver, $approval, 'approve'))->toThrow(ValidationException::class);
        } else {
            app(ResolvePostingApproval::class)->handle($approver, $approval, 'approve');
        }
        expect($approval->fresh()->status)->toBe($enabled ? 'pending' : 'approved');
    });
})->with([true, false]);

test('cash refunds without shifts keep actor audit and idempotency', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);
    inTenant($property, function () use ($owner) {
        app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $folio = Folio::query()->sole();
        app(RecordPayment::class)->handle($owner, $folio, ['method' => 'bank_transfer', 'currency' => 'USD', 'amount' => 1500]);
        $details = ['method' => 'cash', 'amount' => 500, 'reason' => 'Guest refund', 'idempotency_key' => 'refund-no-shift'];
        $refund = app(RecordAdjustment::class)->handle($owner, $folio, 'refund', $details);
        $again = app(RecordAdjustment::class)->handle($owner, $folio, 'refund', $details);

        expect($again->id)->toBe($refund->id)
            ->and($refund->actor_id)->toBe($owner->id)
            ->and($refund->cashier_shift_id)->toBeNull()
            ->and($refund->method)->toBe('cash')
            ->and($folio->balance()->amount)->toBe(-1000)
            ->and(Activity::query()->where('subject_id', $refund->id)->where('causer_id', $owner->id)->where('description', 'Controlled posting recorded')->count())->toBe(1);
    });
});

test('opening is unavailable while shifts are off but the authorized history page remains reachable', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);
    $this->actingAs($owner)->get(route('cashier-shifts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('canOpenShift', false));
    $this->post(route('cashier-shifts.store'), ['_property' => $property->id, 'floats' => ['USD' => 0]])
        ->assertSessionHasErrors('shift');
    expect(inTenant($property, fn () => CashierShift::query()->exists()))->toBeFalse();
});

test('a legacy open shift can still be closed while new opening is disabled', function () {
    [$owner, , $property] = organizationWithOwner();
    inTenant($property, fn () => app(OpenCashierShift::class)->handle($owner, ['USD' => 0]));
    $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);
    $this->actingAs($owner)->get(route('cashier-shifts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('canOpenShift', false)->where('canCloseShift', true)->where('openShift.cashier', $owner->name));
});

test('permitted shift history stays discoverable after turning shifts off', function () {
    $t = twoTenants();
    $property = $t->a->property;
    inTenant($property, function () use ($t) {
        $shift = app(OpenCashierShift::class)->handle($t->a->owner, ['USD' => 0]);
        app(CloseCashierShift::class)->handle($t->a->owner, $shift, ['USD' => 0], null);
    });
    $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);
    $this->actingAs($t->a->member(OrganizationRole::Finance))->get(route('payments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('cashierShiftHistoryUrl', route('cashier-shifts.index')));
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->get(route('payments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('cashierShiftHistoryUrl', null));
});

test('cash without a cashier is refused even when shifts are optional', function (string $operation) {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['payment_settings' => ['use_cashier_shifts' => false]]);
    inTenant($property, function () use ($owner, $operation) {
        app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $folio = Folio::query()->sole();
        $post = fn () => $operation === 'payment'
            ? app(RecordPayment::class)->handle(null, $folio, ['method' => 'cash', 'currency' => 'USD', 'amount' => 1500])
            : app(RecordAdjustment::class)->handle(null, $folio, 'refund', ['method' => 'cash', 'amount' => 500, 'reason' => 'Refund', 'idempotency_key' => 'no-cashier']);
        expect($post)->toThrow(ValidationException::class);
        expect($folio->postings()->count())->toBe(0);
    });
})->with(['payment', 'refund']);

test('an open shift at another property cannot fund a cash posting in organization context', function (string $operation) {
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(OpenCashierShift::class)->handle($t->a->owner, ['USD' => 0]));
    $folio = inTenant($t->a->otherProperty, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return Folio::query()->sole();
    });
    inTenant($t->a->organization, function () use ($t, $folio, $operation) {
        $post = fn () => $operation === 'payment'
            ? app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'cash', 'currency' => 'USD', 'amount' => 1500])
            : app(RecordAdjustment::class)->handle($t->a->owner, $folio, 'refund', ['method' => 'cash', 'amount' => 500, 'reason' => 'Refund', 'idempotency_key' => 'wrong-property']);
        expect($post)->toThrow(ValidationException::class);
        expect($folio->postings()->count())->toBe(0);
    });
})->with(['payment', 'refund']);
