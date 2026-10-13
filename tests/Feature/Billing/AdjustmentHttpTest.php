<?php

use App\Domains\Billing\Actions\OpenCashierShift;
use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\SetApprovalThreshold;
use App\Domains\Billing\Models\ApprovalThreshold;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('finance records a repeat-safe refund with a reason', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $this->actingAs($t->a->member(OrganizationRole::Finance));
    $details = ['_property' => $t->a->property->id, 'amount' => 500, 'method' => 'bank_transfer', 'reason' => 'Deposit returned', 'idempotency_key' => 'refund-1'];

    $this->post(route('folios.refunds.store', $folio), $details)->assertRedirect();
    $this->post(route('folios.refunds.store', $folio), $details)->assertRedirect();
    $this->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('folios.0.balance', 500)->has('folios.0.postings', 1)
        ->where('folios.0.postings.0.type', 'refund')->where('folios.0.postings.0.reason', 'Deposit returned'));
});

test('a refund above the currency threshold waits for a different approver', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->put(route('approval-thresholds.update'), [
        '_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => 400,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('folios.refunds.store', $folio), [
        '_property' => $t->a->property->id, 'amount' => 500, 'method' => 'bank_transfer', 'reason' => 'Deposit returned', 'idempotency_key' => 'pending-1',
    ])->assertRedirect();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('folios.0.balance', 0)->has('folios.0.postings', 0));
    $approvalId = null;
    $this->get(route('approvals.index'))->assertOk()->assertInertia(function (Assert $page) use (&$approvalId) {
        $page->has('approvals.data', 1)->where('approvals.data.0.status', 'pending')
            ->where('approvals.data.0.requester', 'Alpha Owner')
            ->where('approvals.data.0.id', function ($id) use (&$approvalId) {
                $approvalId = $id;

                return true;
            });
    });
    $this->postJson(route('approvals.resolve', $approvalId), [
        '_property' => $t->a->property->id, 'decision' => 'approve',
    ])->assertUnprocessable()->assertJsonValidationErrors('approval');
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('approvals.resolve', $approvalId), [
        '_property' => $t->a->property->id, 'decision' => 'approve',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('folios.0.balance', 500)->has('folios.0.postings', 1));
});

test('a reversal above threshold posts only after approval and cannot be moved while pending', function () {
    $t = twoTenants();
    [$reservation, $folio, $charge] = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $folio = Folio::query()->sole();

        return [$reservation, $folio, app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 500])];
    });
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => 400])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('postings.reverse', $charge), [
        '_property' => $t->a->property->id, 'reason' => 'Wrong folio',
    ])->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('folios.0.balance', 500)->has('folios.0.postings', 1));
    $approval = inTenant($t->a->property, fn () => PostingApproval::query()->sole());
    $target = inTenant($t->a->property, fn () => app(OpenFolio::class)->handle($t->a->owner, $reservation, 'USD'));
    $this->post(route('postings.transfer', $charge), ['_property' => $t->a->property->id, 'folio_id' => $target->id, 'reason' => 'Move'])->assertSessionHasErrors('posting');
    $this->post(route('postings.reverse', $charge), ['_property' => $t->a->property->id, 'reason' => 'Again'])->assertSessionHasErrors('posting');
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('approvals.resolve', $approval), [
        '_property' => $t->a->property->id, 'decision' => 'approve',
    ])->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('folios.0.balance', 0)->has('folios.0.postings', 2)->where('folios.0.postings.1.related.id', $charge->id));
});

test('each role has explicit refund and write-off access', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, function () use ($t) {
        $folio = Folio::query()->sole();
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 500]);

        return $folio;
    });
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);
    $this->actingAs($actor)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    foreach (['refunds', 'write-offs'] as $operation) {
        $response = $this->post(route("folios.$operation.store", $folio), [
            '_property' => $t->a->property->id, 'amount' => 100, 'method' => 'bank_transfer', 'reason' => 'Correction', 'idempotency_key' => $operation,
        ]);
        $allowed ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
    }
    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe($allowed ? 3 : 1);
    if ($role !== OrganizationRole::Housekeeping) {
        $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('canRefund', $allowed)->where('canWriteOff', $allowed));
    }
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::Finance], true)], OrganizationRole::cases()));

test('each role has explicit threshold and approval access', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $approval = inTenant($t->a->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        app(SetApprovalThreshold::class)->handle($t->a->owner, 'USD', 0);

        return app(RecordAdjustment::class)->handle($t->a->member(OrganizationRole::Finance), Folio::query()->sole(), 'refund', [
            'amount' => 100, 'method' => 'bank_transfer', 'reason' => 'Correction', 'idempotency_key' => 'role-approval',
        ]);
    });
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);
    $this->actingAs($actor)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $list = $this->get(route('approvals.index'));
    $threshold = $this->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => null]);
    $decision = $this->post(route('approvals.resolve', $approval), ['_property' => $t->a->property->id, 'decision' => 'reject', 'reason' => 'Not authorized']);
    $allowed ? $list->assertOk() : $list->assertForbidden();
    foreach ([$threshold, $decision] as $response) {
        $allowed ? $response->assertRedirect()->assertSessionHasNoErrors() : $response->assertForbidden();
    }
    expect(inTenant($t->a->property, fn () => PostingApproval::query()->sole()->status))->toBe($allowed ? 'rejected' : 'pending');
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true)], OrganizationRole::cases()));

test('write-offs clear only unpaid balances and require reasons', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, function () use ($t) {
        $folio = Folio::query()->sole();
        app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 500]);

        return $folio;
    });
    $this->actingAs($t->a->member(OrganizationRole::Finance));
    $details = ['_property' => $t->a->property->id, 'amount' => 500, 'reason' => 'Uncollectable', 'idempotency_key' => 'write-off'];
    $this->postJson(route('folios.write-offs.store', $folio), [...$details, 'reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson(route('folios.write-offs.store', $folio), [...$details, 'amount' => 501])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->post(route('folios.write-offs.store', $folio), $details)->assertSessionHasNoErrors();
    $this->post(route('folios.write-offs.store', $folio), $details)->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('folios.0.balance', 0)->has('folios.0.postings', 2)->where('folios.0.postings.1.type', 'write_off'));
});

test('cash refunds require the requesting cashiers open shift and reduce expected cash', function () {
    $t = twoTenants();
    $finance = $t->a->member(OrganizationRole::Finance);
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $this->actingAs($finance);
    $details = ['_property' => $t->a->property->id, 'amount' => 100, 'method' => 'cash', 'reason' => 'Deposit returned', 'idempotency_key' => 'cash-refund'];
    $this->postJson(route('folios.refunds.store', $folio), $details)->assertUnprocessable()->assertJsonValidationErrors('method');
    $this->postJson(route('folios.refunds.store', $folio), [...$details, 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $shift = inTenant($t->a->property, fn () => app(OpenCashierShift::class)->handle($finance, ['USD' => 500]));
    $this->post(route('folios.refunds.store', $folio), $details)->assertSessionHasNoErrors();
    $this->get(route('cashier-shifts.index'))->assertInertia(fn (Assert $page) => $page->where('openShift.currencies.0.expected', 400));
    expect(inTenant($t->a->property, fn () => Posting::query()->sole()->cashier_shift_id))->toBe($shift->id);
});

test('rejections require a reason, preserve balances and cannot later be approved', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => 0])->assertSessionHasNoErrors();
    $details = ['_property' => $t->a->property->id, 'amount' => 100, 'method' => 'bank_transfer', 'reason' => 'Correction', 'idempotency_key' => 'reject-refund'];
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('folios.refunds.store', $folio), $details)->assertSessionHasNoErrors();
    $approval = inTenant($t->a->property, fn () => PostingApproval::query()->sole());
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    $this->postJson(route('approvals.resolve', $approval), ['_property' => $t->a->property->id, 'decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->post(route('approvals.resolve', $approval), ['_property' => $t->a->property->id, 'decision' => 'reject', 'reason' => 'Not due'])->assertSessionHasNoErrors();
    $this->post(route('approvals.resolve', $approval), ['_property' => $t->a->property->id, 'decision' => 'approve'])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('folios.refunds.store', $folio), $details)->assertSessionHasNoErrors();
    expect(inTenant($t->a->property, fn () => [PostingApproval::query()->sole()->status, PostingApproval::query()->sole()->rejection_reason, Folio::query()->sole()->balance()->amount]))
        ->toBe(['rejected', 'Not due', 0]);
});

test('none disables only the selected property and currency and equality posts immediately', function () {
    $t = twoTenants();
    [$folio, $tzs] = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return [Folio::query()->sole(), app(OpenFolio::class)->handle($t->a->owner, $reservation, 'TZS')];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager));
    foreach (['USD', 'TZS'] as $currency) {
        $this->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => $currency, 'amount' => 100])->assertSessionHasNoErrors();
    }
    $this->actingAs($t->a->member(OrganizationRole::Finance));
    $details = ['_property' => $t->a->property->id, 'amount' => 100, 'method' => 'bank_transfer', 'reason' => 'Correction', 'idempotency_key' => 'equal'];
    $this->post(route('folios.refunds.store', $folio), $details)->assertSessionHasNoErrors();
    $this->post(route('folios.refunds.store', $tzs), [...$details, 'amount' => 101])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => null])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('folios.refunds.store', $folio), [...$details, 'amount' => 1000, 'idempotency_key' => 'none'])->assertSessionHasNoErrors();
    expect(inTenant($t->a->property, fn () => [Folio::query()->findOrFail($folio->id)->balance()->amount, Folio::query()->findOrFail($tzs->id)->balance()->amount]))->toBe([1100, 0]);
    expect(inTenant($t->a->otherProperty, fn () => ApprovalThreshold::query()->count()))->toBe(0);
});

test('a pending cash refund posts against its requesting shift, not the approvers', function () {
    $t = twoTenants();
    $finance = $t->a->member(OrganizationRole::Finance);
    [$folio, $shift] = inTenant($t->a->property, function () use ($t, $finance) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return [Folio::query()->sole(), app(OpenCashierShift::class)->handle($finance, ['USD' => 500])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => 0])->assertSessionHasNoErrors();
    $this->actingAs($finance)->post(route('folios.refunds.store', $folio), ['_property' => $t->a->property->id, 'amount' => 100, 'method' => 'cash', 'reason' => 'Deposit returned', 'idempotency_key' => 'pending-cash'])->assertSessionHasNoErrors();
    $this->get(route('cashier-shifts.index'))->assertInertia(fn (Assert $page) => $page->where('openShift.currencies.0.expected', 500));
    $approval = inTenant($t->a->property, fn () => PostingApproval::query()->sole());
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('approvals.resolve', $approval), ['_property' => $t->a->property->id, 'decision' => 'approve'])->assertSessionHasNoErrors();
    $this->actingAs($finance)->get(route('cashier-shifts.index'))->assertInertia(fn (Assert $page) => $page->where('openShift.currencies.0.expected', 400));
    expect(inTenant($t->a->property, fn () => Posting::query()->sole()->cashier_shift_id))->toBe($shift->id);
});

test('a closed requesting shift cannot receive an approved cash refund', function () {
    $t = twoTenants();
    $finance = $t->a->member(OrganizationRole::Finance);
    [$folio, $shift] = inTenant($t->a->property, function () use ($t, $finance) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return [Folio::query()->sole(), app(OpenCashierShift::class)->handle($finance, ['USD' => 500])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => 0])->assertSessionHasNoErrors();
    $this->actingAs($finance)->post(route('folios.refunds.store', $folio), ['_property' => $t->a->property->id, 'amount' => 100, 'method' => 'cash', 'reason' => 'Deposit returned', 'idempotency_key' => 'closed-cash'])->assertSessionHasNoErrors();
    $approval = inTenant($t->a->property, fn () => PostingApproval::query()->sole());
    $this->post(route('cashier-shifts.close', $shift), ['_property' => $t->a->property->id, 'counted' => ['USD' => 500]])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->postJson(route('approvals.resolve', $approval), ['_property' => $t->a->property->id, 'decision' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('approval');
    expect(inTenant($t->a->property, fn () => [Posting::query()->count(), PostingApproval::query()->sole()->status, CashierShift::query()->sole()->expectedCash()]))->toEqual([0, 'pending', ['USD' => 500]]);
});

test('an approval rechecks the unpaid balance before posting a write-off', function () {
    $t = twoTenants();
    [$folio, $charge] = inTenant($t->a->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $folio = Folio::query()->sole();

        return [$folio, app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 500])];
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => 400])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('folios.write-offs.store', $folio), ['_property' => $t->a->property->id, 'amount' => 500, 'reason' => 'Uncollectable', 'idempotency_key' => 'pending-write-off'])->assertSessionHasNoErrors();
    $approval = inTenant($t->a->property, fn () => PostingApproval::query()->sole());
    $this->post(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'amount' => 500, 'method' => 'bank_transfer', 'idempotency_key' => 'paid'])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->postJson(route('approvals.resolve', $approval), ['_property' => $t->a->property->id, 'decision' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('approval');
    expect(inTenant($t->a->property, fn () => [Folio::query()->sole()->balance()->amount, PostingApproval::query()->sole()->status]))->toBe([0, 'pending']);
});

test('a rejected reversal can be resubmitted without posting the rejected request', function () {
    $t = twoTenants();
    $charge = inTenant($t->a->property, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));

        return app(PostExtraCharge::class)->handle($t->a->owner, Folio::query()->sole(), ['category' => 'other', 'quantity' => 1, 'unit_price' => 500]);
    });
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->put(route('approval-thresholds.update'), ['_property' => $t->a->property->id, 'currency' => 'USD', 'amount' => 0])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('postings.reverse', $charge), ['_property' => $t->a->property->id, 'reason' => 'Wrong room'])->assertSessionHasNoErrors();
    $first = inTenant($t->a->property, fn () => PostingApproval::query()->sole());
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('approvals.resolve', $first), ['_property' => $t->a->property->id, 'decision' => 'reject', 'reason' => 'Need a clearer reason'])->assertSessionHasNoErrors();
    $this->actingAs($t->a->member(OrganizationRole::Finance))->post(route('postings.reverse', $charge), ['_property' => $t->a->property->id, 'reason' => 'Wrong room, reservation corrected'])->assertSessionHasNoErrors();
    $second = inTenant($t->a->property, fn () => PostingApproval::query()->where('status', 'pending')->sole());
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('approvals.resolve', $second), ['_property' => $t->a->property->id, 'decision' => 'approve'])->assertSessionHasNoErrors();
    expect(inTenant($t->a->property, fn () => [PostingApproval::query()->findOrFail($first->id)->posting_id, Posting::query()->count(), Folio::query()->sole()->balance()->amount]))->toBe([null, 2, 0]);
});
