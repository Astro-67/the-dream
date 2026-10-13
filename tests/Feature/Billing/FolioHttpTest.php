<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('only roles with charges.post post an extra charge', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($actor)->post(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'category' => 'minibar', 'description' => 'Water', 'quantity' => 2, 'unit_price' => 150, 'idempotency_key' => 'k-1']);

    $allowed ? $response->assertRedirect(route('reservations.show', $reservation)) : $response->assertForbidden();
    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe($allowed ? 1 : 0);
    if ($role !== OrganizationRole::Housekeeping) {
        $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
            ->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canPostCharge', $allowed));
    }
})->with(array_map(fn (OrganizationRole $role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::FrontDesk, OrganizationRole::Finance], true)], OrganizationRole::cases()));

test('a resubmitted charge form posts once', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $form = ['_property' => $t->a->property->id, 'category' => 'laundry', 'quantity' => 1, 'unit_price' => 900, 'idempotency_key' => '01JABCDEFGHJKMNPQRSTVWXYZ0'];
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));

    $this->post(route('folios.charges.store', $folio), $form)->assertSessionHasNoErrors();
    $this->post(route('folios.charges.store', $folio), $form)->assertSessionHasNoErrors();

    expect(inTenant($t->a->property, fn () => Posting::query()->count()))->toBe(1);
});

test('the charge form validates category, quantity, price and key', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());

    $this->actingAs($t->a->owner)->postJson(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'category' => 'spa', 'quantity' => 0, 'unit_price' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors(['category', 'quantity', 'unit_price', 'idempotency_key']);
});

test('the reservation shows its folio postings, totals per currency and balance', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    $folio = inTenant($t->a->property, fn () => Folio::query()->sole());
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    $this->post(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'category' => 'minibar', 'quantity' => 2, 'unit_price' => 150, 'idempotency_key' => 'a']);
    $this->post(route('folios.charges.store', $folio), ['_property' => $t->a->property->id, 'category' => 'laundry', 'quantity' => 1, 'unit_price' => 1000, 'idempotency_key' => 'b']);

    $this->actingAs($t->a->member(OrganizationRole::Auditor))->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('folios', 1)
        ->where('folios.0.id', $folio->id)->where('folios.0.currency', 'USD')
        ->where('folios.0.balance', 1300)
        ->has('folios.0.postings', 2)
        ->where('folios.0.postings.0.category', 'minibar')->where('folios.0.postings.0.amount', 300)
        ->where('totals', [['currency' => 'USD', 'charges' => 1300, 'balance' => 1300]]));
});
