<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\PostRoomCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Actions\ReplaceIdentityDocuments;
use App\Domains\Guests\Models\Guest;
use App\Domains\Rates\Actions\ChangeTaxRule;
use App\Domains\Rates\Actions\SeedDefaultTaxRules;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

/**
 * Property A of two tenants at Zanzibar's 4-star defaults (15% VAT included, USD 5 levy added), on 2026-12-10,
 * with one adult booked for two nights at USD 100, its lead guest carrying a passport and a private note.
 */
function statementStay(): array
{
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10', 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]);
    $reservation = inTenant($t->a->property, function () use ($t) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $t->a->property);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [...BookingForm::details($t->a->owner), 'adults' => 1, 'children' => 0]);
        app(AttachReservationGuest::class)->handle($t->a->owner, $reservation, ['name' => 'Amina Statement', 'notes' => 'Private staff note']);
        app(ReplaceIdentityDocuments::class)->handle(Guest::query()->sole(), [['type' => 'passport', 'number' => 'P9988776', 'nationality' => 'TZ']]);
        $reservation->update(['status' => ReservationStatus::CheckedIn]);

        return $reservation;
    });

    return [$t, $reservation];
}

// protects=page and statement show the spec's example from stored postings; fails_when=VAT is doubled, tax is recomputed or an estimate counts as posted.
test('the reservation and its statement reconcile the posted example and keep the unposted night as an estimate', function () {
    [$t, $reservation] = statementStay();
    inTenant($t->a->property, function () use ($t, $reservation) {
        app(PostRoomCharge::class)->handle($t->a->owner, $reservation, '2026-12-10');
        app(PostExtraCharge::class)->handle($t->a->owner, Folio::query()->sole(), ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1000]);
        app(RecordPayment::class)->handle($t->a->owner, Folio::query()->sole(), ['method' => 'bank_transfer', 'amount' => 10000, 'currency' => 'USD']);
        // A later rate change must not rewrite what was posted.
        $vat = TaxRule::query()->where('name', 'VAT')->sole();
        app(ChangeTaxRule::class)->handle($t->a->owner, $vat, [...$vat->terms(), 'rate' => 1800, 'effective_from' => '2026-12-11']);
    });
    $expected = ['currency' => 'USD', 'room' => 8696, 'extras' => 870, 'vat' => 1434, 'levy' => 500, 'other_taxes' => 0, 'unclassified_taxes' => 0,
        'charges' => 11500, 'paid' => 10000, 'refunded' => 0, 'written_off' => 0, 'net_payments' => 10000, 'balance' => 1500, 'levy_unavailable' => false, 'estimate' => ['nights' => 1, 'amount' => 10000], 'due' => 11500, 'status' => 'partially_paid'];

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals', [$expected])->where('assignedRoom', null));
    $this->get(route('reservations.statement', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page->component('reservations/statement')
        ->where('totals', [$expected])
        ->where('reservation.guest', 'Amina Statement')->where('reservation.nights', 2)->where('reservation.room_type', 'Deluxe Double')
        ->has('folios', 1)->where('folios.0.summary.balance', 1500)->has('folios.0.postings', 3)
        ->where('folios.0.postings.0.tax_lines', [['name' => 'Infrastructure levy', 'inclusive' => false, 'amount' => 500, 'unavailable' => false], ['name' => 'VAT', 'inclusive' => true, 'amount' => 1304, 'unavailable' => false]]))
        ->assertDontSee('P9988776')->assertDontSee('Private staff note');
});

// protects=one section per currency, never converted; fails_when=currencies are summed or a settled folio hides unposted nights.
test('each currency is summarised apart, and a zero balance with unposted nights is not settled', function () {
    [$t, $reservation] = statementStay();
    inTenant($t->a->property, function () use ($t, $reservation) {
        app(OpenFolio::class)->handle($t->a->owner, $reservation, 'TZS');
        app(PostExtraCharge::class)->handle($t->a->owner, Folio::query()->where('currency', 'TZS')->sole(), ['category' => 'laundry', 'quantity' => 1, 'unit_price' => 115000]);
    });

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->get(route('reservations.statement', $reservation))->assertInertia(fn (Assert $page) => $page
        ->has('folios', 2)->where('totals.0.currency', 'TZS')->where('totals.0.charges', 115000)->where('totals.0.estimate', null)
        ->where('totals.1.currency', 'USD')->where('totals.1.balance', 0)->where('totals.1.estimate', ['nights' => 2, 'amount' => 20000]));
});

// protects=early departure drops unused nights from the estimate only; fails_when=posted nights change or released nights stay estimated.
test('after an early departure the estimate is gone and the posted night is unchanged', function () {
    [$t, $reservation] = statementStay();
    $posting = inTenant($t->a->property, fn () => app(PostRoomCharge::class)->handle($t->a->owner, $reservation, '2026-12-10'));
    inTenant($t->a->property, fn () => Reservation::query()->whereKey($reservation->id)->update(['departure' => '2026-12-11']));
    inTenant($t->a->property, fn () => Reservation::query()->sole()->priceLines()->whereDate('date', '2026-12-11')->delete());

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->get(route('reservations.statement', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.estimate', null)->where('totals.0.charges', $posting->amount)->where('reservation.nights', 1));
    expect(inTenant($t->a->property, fn () => Posting::query()->sole()->amount))->toBe($posting->amount);
});

test('anyone who may view the reservation may open its statement', function (OrganizationRole $role, bool $allowed) {
    [$t, $reservation] = statementStay();
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = $this->actingAs($actor)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.statement', $reservation));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(array_map(fn (OrganizationRole $role) => [$role, $role !== OrganizationRole::Housekeeping], OrganizationRole::cases()));

test('a statement of a sibling property reservation is not shown from another property', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->otherProperty, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.statement', $reservation))->assertNotFound();
});

// protects=a USD levy on a TZS stay is shown as unavailable from the posting itself; fails_when=it is converted, dropped silently or shown as zero.
test('a foreign-currency levy is posted as unavailable and shown so, not as zero', function () {
    $t = twoTenants();
    $t->a->property->update(['business_date' => '2026-12-10', 'jurisdiction_profile' => 'zanzibar', 'star_rating' => 4]);
    $reservation = inTenant($t->a->property, function () use ($t) {
        app(SeedDefaultTaxRules::class)->handle($t->a->owner, $t->a->property);
        $details = BookingForm::details($t->a->owner);
        RatePlan::query()->whereKey($details['rate_plan_id'])->update(['currency' => 'TZS']);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [...$details, 'adults' => 1, 'children' => 0]);
        $reservation->update(['status' => ReservationStatus::CheckedIn]);
        app(PostRoomCharge::class)->handle($t->a->owner, $reservation, '2026-12-10');

        return $reservation;
    });

    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.statement', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.currency', 'TZS')->where('totals.0.levy', 0)->where('totals.0.levy_unavailable', true)
        ->where('folios.0.summary.levy_unavailable', true)
        ->where('folios.0.postings.0.tax_lines', [['name' => 'VAT', 'inclusive' => true, 'amount' => 1304, 'unavailable' => false], ['name' => 'Infrastructure levy', 'inclusive' => false, 'amount' => 0, 'unavailable' => true]]));
});
