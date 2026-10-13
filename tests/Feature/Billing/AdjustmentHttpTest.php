<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
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
        $page->has('approvals', 1)->where('approvals.0.status', 'pending')
            ->where('approvals.0.id', function ($id) use (&$approvalId) {
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
    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))->post(route('approvals.resolve', $approval), [
        '_property' => $t->a->property->id, 'decision' => 'approve',
    ])->assertSessionHasNoErrors();
    $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page->where('folios.0.balance', 0)->has('folios.0.postings', 2)->where('folios.0.postings.1.related.id', $charge->id));
});
