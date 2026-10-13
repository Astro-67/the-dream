<?php

use App\Domains\Billing\Models\Folio;
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
