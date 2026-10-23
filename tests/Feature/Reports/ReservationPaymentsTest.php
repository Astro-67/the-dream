<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\Isolation\RouteCoverage;

test('the reservation filter applies to both payment rows and received totals', function () {
    $t = twoTenants();
    [$reservation, $folio, $otherFolio] = inTenant($t->a->property, function () use ($t) {
        $t->a->property->update(['business_date' => '2026-06-01']);
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, [...InventoryForm::room($details['room_type_id']), 'number' => '102']);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, $details);
        $other = app(CreateReservation::class)->handle($t->a->owner, $details);

        return [$reservation, Folio::query()->where('reservation_id', $reservation->id)->sole(), Folio::query()->where('reservation_id', $other->id)->sole()];
    });
    $this->actingAs($t->a->owner)->withSession([
        'tenancy.organization_id' => $t->a->organization->id,
        'tenancy.property_id' => $t->a->property->id,
    ]);
    foreach ([[$folio, 2500, 'SELECTED-151'], [$otherFolio, 9000, 'OTHER-151']] as [$account, $amount, $reference]) {
        $this->post(route('folios.payments.store', $account), [
            '_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => $amount,
            'reference' => $reference, 'idempotency_key' => $reference,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    $this->get(route('payments.index', ['reservation' => $reservation->id, 'period' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-01']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.reservation', $reservation->id)
        ->where('reservationNumber', $reservation->reservation_number)
        ->has('payments.data', 1)
        ->where('payments.data.0.reference', 'SELECTED-151')
        ->where('received.totals.0.payments', 2500)
        ->where('received.totals.0.net', 2500));
});

test('the payment reservation filter rejects another property or organization', function (string $tenant, string $view) {
    $t = twoTenants();
    $other = $tenant === 'same organization' ? $t->a->otherProperty : $t->b->property;
    $owner = $tenant === 'same organization' ? $t->a->owner : $t->b->owner;
    $reservation = inTenant($other, function () use ($owner) {
        $reservation = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        app(RecordPayment::class)->handle($owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => 7500, 'currency' => 'USD']);

        return $reservation;
    });

    $response = $this->actingAs($t->a->owner)->withSession([
        'tenancy.organization_id' => $t->a->organization->id,
        'tenancy.property_id' => $t->a->property->id,
    ])->getJson(route('payments.index', ['reservation' => $reservation->id, 'view' => $view]))
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');
    $response->assertDontSee($reservation->reservation_number);
    RouteCoverage::assertNothingOf($t->b, $response);
})->with(['same organization', 'different organization'])->with(['received', 'outstanding']);

test('the payment reservation filter rejects malformed and missing reservations', function (mixed $reservation) {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->withSession([
        'tenancy.organization_id' => $t->a->organization->id,
        'tenancy.property_id' => $t->a->property->id,
    ])->getJson(route('payments.index', ['reservation' => $reservation]))
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');
})->with(['invalid', '01JABCDEFGHJKMNPQRSTVWXYZ00', ['unexpected']]);

test('the reservation filter also scopes the outstanding view', function () {
    $t = twoTenants();
    [$reservation, $folio] = inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        app(CreateRoom::class)->handle($t->a->owner, [...InventoryForm::room($details['room_type_id']), 'number' => '102']);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, $details);
        app(CreateReservation::class)->handle($t->a->owner, $details);

        return [$reservation, Folio::query()->where('reservation_id', $reservation->id)->sole()];
    });

    $this->actingAs($t->a->owner)->withSession([
        'tenancy.organization_id' => $t->a->organization->id,
        'tenancy.property_id' => $t->a->property->id,
    ])->get(route('payments.index', [
        'reservation' => $reservation->id, 'view' => 'outstanding', 'settled' => 1,
        'period' => 'custom', 'from' => '2026-12-10', 'to' => '2026-12-12',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('folios.data', 1)->where('folios.data.0.id', $folio->id));
});
